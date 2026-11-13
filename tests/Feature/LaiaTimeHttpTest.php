<?php

use App\Services\LaiaTime\HttpLaiaTimeApi;
use App\Services\LaiaTime\LaiaTimeException;
use App\Services\LaiaTime\LaiaTimeInstance;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const LT_KEY = 'lt_live_abc123.s3cr3tvalue';

function ltApi(?string $key = LT_KEY, string $url = 'https://time.example.com'): HttpLaiaTimeApi
{
    Settings::set('laiatime.base_url', $url);
    Settings::set('laiatime.api_key', $key);

    return new HttpLaiaTimeApi;
}

function ltTenant(string $id = 't1', string $status = 'ACTIVE'): array
{
    return ['id' => $id, 'name' => "Negocio {$id}", 'subscription' => ['plan' => 'pro', 'status' => $status, 'renewsAt' => '2026-12-01T00:00:00Z']];
}

function ltCatch(callable $fn): LaiaTimeException
{
    try {
        $fn();
    } catch (LaiaTimeException $e) {
        return $e;
    }

    throw new RuntimeException('Se esperaba LaiaTimeException');
}

describe('configuración', function () {
    it('sin URL o sin key no hace llamadas de red', function () {
        Http::fake();

        $api = ltApi('', '');
        expect($api->isConfigured())->toBeFalse()
            ->and($api->configurationIssue())->toBe('not_configured')
            ->and(ltCatch(fn () => $api->instances())->isNotConfigured())->toBeTrue();

        Http::assertNothingSent();
    });

    it('detecta una key con formato inválido sin llamar a la API', function () {
        Http::fake();

        $api = ltApi('clave-cualquiera');
        expect($api->configurationIssue())->toBe('malformed_key')
            ->and(ltCatch(fn () => $api->ping())->reason)->toBe(LaiaTimeException::MALFORMED_KEY);

        Http::assertNothingSent();
    });
});

describe('lectura', function () {
    it('envía el Bearer, acepta la URL con o sin /api/v1 y pagina por cursor', function () {
        Http::fake([
            '*/api/v1/tenants?limit=100' => Http::response(['data' => [ltTenant('a'), ltTenant('b', 'PAST_DUE')], 'next_cursor' => 'c2']),
            '*/api/v1/tenants?limit=100&cursor=c2' => Http::response(['data' => [ltTenant('c', 'TRIAL')], 'next_cursor' => null]),
        ]);

        $list = ltApi(url: 'https://time.example.com/api/v1/')->instances();

        expect(collect($list)->pluck('id')->all())->toBe(['a', 'b', 'c'])
            ->and($list[1]->status)->toBe(LaiaTimeInstance::PAST_DUE)
            ->and($list[2]->status)->toBe(LaiaTimeInstance::TRIAL);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.LT_KEY) && str_starts_with($r->url(), 'https://time.example.com/api/v1/tenants'));
        Http::assertSentCount(2);
    });

    it('corta si el cursor se repite', function () {
        Http::fake(['*' => Http::response(['data' => [ltTenant('a')], 'next_cursor' => 'loop'])]);

        expect(ltApi()->instances())->toHaveCount(1);
        Http::assertSentCount(2);
    });

    it('lee el ETag del GET de un negocio', function () {
        Http::fake(['*/tenants/t1' => Http::response(ltTenant('t1'), 200, ['ETag' => '"v7"'])]);

        $i = ltApi()->instance('t1');

        expect($i->etag)->toBe('"v7"')->and($i->plan)->toBe('pro')->and($i->isActive())->toBeTrue();
    });

    it('una respuesta que no es lista se informa como respuesta inválida', function () {
        Http::fake(['*' => Http::response(['data' => 'x'])]);

        expect(ltCatch(fn () => ltApi()->instances())->reason)->toBe(LaiaTimeException::INVALID_RESPONSE);
    });
});

describe('errores', function () {
    it('mapea 401, 403 y 404', function (int $status, string $reason, string $kind) {
        Http::fake(['*' => Http::response(['code' => 'X', 'message' => 'no'], $status)]);

        $e = ltCatch(fn () => ltApi()->instance('t1'));

        expect($e->reason)->toBe($reason)->and($e->kind())->toBe($kind);
    })->with([
        [401, LaiaTimeException::AUTH, 'credentials'],
        [403, LaiaTimeException::SCOPE, 'credentials'],
        [404, LaiaTimeException::NOT_FOUND, 'unavailable'],
    ]);

    it('un 5xx y un corte de red se tratan como API no disponible', function () {
        Http::fake(['*' => Http::response('boom', 503)]);
        expect(ltCatch(fn () => ltApi()->ping())->kind())->toBe('unavailable');

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $e = ltCatch(fn () => ltApi()->ping());
        expect($e->reason)->toBe(LaiaTimeException::NETWORK)
            ->and($e->friendlyMessage())->toBe(LaiaTimeException::UNAVAILABLE_MESSAGE);
    });

    it('un 422 conserva código y campo y nunca deja pasar la API key', function () {
        Http::fake(['*' => Http::response(['error' => ['code' => 'VALIDATION', 'message' => 'bad '.LT_KEY.' / s3cr3tvalue', 'field' => 'status']], 422)]);

        $e = ltCatch(fn () => ltApi()->ping());

        expect($e->reason)->toBe(LaiaTimeException::REJECTED)
            ->and($e->remoteCode)->toBe('VALIDATION')->and($e->field)->toBe('status')
            ->and($e->getMessage())->not->toContain('s3cr3tvalue')->not->toContain(LT_KEY);
    });

    it('un 429 espera Retry-After y reintenta una vez', function () {
        Sleep::fake();
        Http::fakeSequence()->push('', 429, ['Retry-After' => '2'])->push(['data' => [ltTenant('a')], 'next_cursor' => null]);

        expect(ltApi()->instances())->toHaveCount(1);

        Sleep::assertSleptTimes(1);
        Http::assertSentCount(2);
    });

    it('un 429 repetido, o con espera larga, se informa con el Retry-After', function () {
        Sleep::fake();
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '30'])]);

        $e = ltCatch(fn () => ltApi()->ping());

        expect($e->reason)->toBe(LaiaTimeException::RATE_LIMITED)->and($e->retryAfter)->toBe(30);
        Http::assertSentCount(1);
    });
});

describe('escritura de la suscripción', function () {
    it('informa mora con PUT, If-Match y conservando plan y renewsAt', function () {
        Http::fake([
            '*/tenants/t1/subscription' => Http::response([], 200),
            '*/tenants/t1' => Http::response(ltTenant('t1'), 200, ['ETag' => '"v7"']),
        ]);

        ltApi()->markPastDue('t1');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/api/v1/tenants/t1/subscription')
            && $r->hasHeader('If-Match', '"v7"')
            && $r->data() === ['plan' => 'pro', 'status' => 'PAST_DUE', 'renewsAt' => '2026-12-01T00:00:00Z']);
    });

    it('es idempotente: si ya está en el estado pedido no escribe', function () {
        Http::fake(['*/tenants/t1' => Http::response(ltTenant('t1', 'PAST_DUE'))]);

        ltApi()->markPastDue('t1');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    });

    it('no toca TRIAL ni CANCELED', function (string $status) {
        Http::fake(['*/tenants/t1' => Http::response(ltTenant('t1', $status))]);

        expect(ltCatch(fn () => ltApi()->markPastDue('t1'))->reason)->toBe(LaiaTimeException::INVALID_STATE);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    })->with(['TRIAL', 'CANCELED']);

    it('reactiva PAST_DUE → ACTIVE', function () {
        Http::fake([
            '*/tenants/t1/subscription' => Http::response([], 200),
            '*/tenants/t1' => Http::response(ltTenant('t1', 'PAST_DUE'), 200, ['ETag' => '"v1"']),
        ]);

        ltApi()->markActive('t1');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data()['status'] === 'ACTIVE');
    });

    it('ante 412 relee y reintenta una vez', function () {
        Http::fake([
            '*/tenants/t1/subscription' => Http::sequence()->push('', 412)->push([], 200),
            '*/tenants/t1' => Http::sequence()->push(ltTenant('t1'), 200, ['ETag' => '"old"'])->push(ltTenant('t1'), 200, ['ETag' => '"new"']),
        ]);

        ltApi()->markPastDue('t1');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('If-Match', '"new"'));
        Http::assertSentCount(4);
    });

    it('si el segundo intento también da 412, informa conflicto', function () {
        Http::fake([
            '*/tenants/t1/subscription' => Http::response('', 412),
            '*/tenants/t1' => Http::response(ltTenant('t1'), 200, ['ETag' => '"x"']),
        ]);

        expect(ltCatch(fn () => ltApi()->markPastDue('t1'))->reason)->toBe(LaiaTimeException::CONFLICT);
    });
});
