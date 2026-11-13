<?php

namespace App\Services\LaiaTime;

use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Cliente HTTP de LaiaTime. URL y API key salen de Configuración (la clave está cifrada en la base).
 * Todo fallo se traduce a {@see LaiaTimeException}; la clave nunca aparece en un mensaje.
 */
class HttpLaiaTimeApi implements LaiaTimeApi
{
    private const TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 5;
    /** Si LaiaTime pide esperar más que esto (segundos), no se espera: se informa. */
    private const MAX_AUTO_WAIT = 10;

    private readonly string $baseUrl;
    private readonly string $key;

    public function __construct()
    {
        $this->baseUrl = trim((string) Settings::get('laiatime.base_url', ''));
        $this->key = trim((string) Settings::get('laiatime.api_key', ''));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->key !== '';
    }

    public function configurationIssue(): ?string
    {
        if (! $this->isConfigured()) {
            return LaiaTimeException::NOT_CONFIGURED;
        }

        return LaiaTimeContract::keyIsValid($this->key) ? null : LaiaTimeException::MALFORMED_KEY;
    }

    public function ping(): void
    {
        $this->page(null, 1);
    }

    public function instances(): array
    {
        $all = [];
        $cursor = null;
        $seen = [];

        for ($i = 0; $i < LaiaTimeContract::MAX_PAGES; $i++) {
            $page = $this->page($cursor, LaiaTimeContract::PAGE_SIZE);

            foreach ($page['items'] as $item) {
                $tenant = LaiaTimeContract::tenant($item);
                $all[$tenant->id] = $tenant;
            }

            $cursor = $page['next'];
            if ($cursor === null || isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
        }

        return array_values($all);
    }

    public function instance(string $id): LaiaTimeInstance
    {
        $response = $this->send('GET', LaiaTimeContract::path(LaiaTimeContract::PATH_TENANT, $id), notFound: 'El negocio');

        return LaiaTimeContract::tenant(LaiaTimeContract::unwrapOne($this->json($response)), $response->header(LaiaTimeContract::HEADER_ETAG));
    }

    public function markPastDue(string $id): void
    {
        $this->transition($id, LaiaTimeInstance::ACTIVE, LaiaTimeInstance::PAST_DUE);
    }

    public function markActive(string $id): void
    {
        $this->transition($id, LaiaTimeInstance::PAST_DUE, LaiaTimeInstance::ACTIVE);
    }

    /**
     * Cambia la suscripción de `$from` a `$to`. Idempotente si ya está en `$to`; TRIAL/CANCELED no se tocan.
     * Ante 412 (alguien modificó en el medio) se vuelve a leer y se reintenta UNA vez.
     */
    private function transition(string $id, string $from, string $to): void
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $current = $this->instance($id);

            if ($current->status === $to) {
                return;
            }
            if ($current->status !== $from) {
                throw LaiaTimeException::invalidState(strtoupper($current->status));
            }

            $headers = $current->etag !== null ? [LaiaTimeContract::HEADER_IF_MATCH => $current->etag] : [];
            $response = $this->send(
                'PUT',
                LaiaTimeContract::path(LaiaTimeContract::PATH_SUBSCRIPTION, $id),
                body: LaiaTimeContract::subscriptionBody($current, $to),
                headers: $headers,
                notFound: 'El negocio',
                acceptPreconditionFailed: true,
            );

            if ($response->status() !== 412) {
                return;
            }
        }

        throw LaiaTimeException::conflict();
    }

    /** @return array{items: list<array<string, mixed>>, next: ?string} */
    private function page(?string $cursor, int $limit): array
    {
        $query = [LaiaTimeContract::PARAM_LIMIT => $limit] + ($cursor !== null ? [LaiaTimeContract::PARAM_CURSOR => $cursor] : []);

        return LaiaTimeContract::page($this->json($this->send('GET', LaiaTimeContract::PATH_TENANTS, query: $query)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function send(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
        string $notFound = 'El recurso',
        bool $acceptPreconditionFailed = false,
    ): Response {
        $issue = $this->configurationIssue();
        if ($issue === LaiaTimeException::NOT_CONFIGURED) {
            throw LaiaTimeException::notConfigured();
        }
        if ($issue === LaiaTimeException::MALFORMED_KEY) {
            throw LaiaTimeException::malformedKey();
        }

        $retried = false;

        while (true) {
            try {
                $request = $this->client($headers);
                $response = $method === 'PUT' ? $request->put($path, $body ?? []) : $request->get($path, $query);
            } catch (ConnectionException) {
                throw LaiaTimeException::network();
            }

            if ($response->status() === 429) {
                $wait = max(1, (int) $response->header('Retry-After'));
                if ($retried || $wait > self::MAX_AUTO_WAIT) {
                    throw LaiaTimeException::rateLimited($wait);
                }
                $retried = true;
                Sleep::for($wait)->seconds();

                continue;
            }

            break;
        }

        if ($response->successful() || ($acceptPreconditionFailed && $response->status() === 412)) {
            return $response;
        }

        throw $this->failure($response, $notFound);
    }

    /** @param  array<string, string>  $headers */
    private function client(array $headers): PendingRequest
    {
        return Http::baseUrl(LaiaTimeContract::baseUrl($this->baseUrl))
            ->withToken($this->key)
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT);
    }

    private function failure(Response $response, string $notFound): LaiaTimeException
    {
        $status = $response->status();
        $error = LaiaTimeContract::error($response->json());

        return match (true) {
            $status === 401 => LaiaTimeException::auth(),
            $status === 403 => LaiaTimeException::scope(),
            $status === 404 => LaiaTimeException::notFound($notFound),
            $status === 409, $status === 412 => LaiaTimeException::conflict(),
            $status >= 500 => LaiaTimeException::network("LaiaTime tuvo un error interno (HTTP {$status}). Se reintenta más tarde."),
            default => LaiaTimeException::rejected(
                $this->redact($error['message'] ?? "LaiaTime rechazó el pedido (HTTP {$status})."),
                $error['code'],
                $error['field'],
            ),
        };
    }

    /** @return array<mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : throw LaiaTimeException::invalidResponse('LaiaTime no respondió un JSON válido.');
    }

    /** Tacha la API key (completa o su secreto) si la API remota la repitiera en un mensaje. */
    private function redact(string $text): string
    {
        $secret = str_contains($this->key, '.') ? substr($this->key, strrpos($this->key, '.') + 1) : '';

        return str_replace(array_filter([$this->key, $secret], fn ($s) => strlen($s) >= 6), '[clave]', $text);
    }
}
