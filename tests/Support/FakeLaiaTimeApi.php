<?php

namespace Tests\Support;

use App\Services\LaiaTime\LaiaTimeApi;
use App\Services\LaiaTime\LaiaTimeException;
use App\Services\LaiaTime\LaiaTimeInstance;

/** LaiaTime en memoria para tests: registra las escrituras y puede simular fallos. */
class FakeLaiaTimeApi implements LaiaTimeApi
{
    /** @var array<string, LaiaTimeInstance> */
    public array $tenants = [];

    /** @var list<string> "past_due:<id>" | "active:<id>" */
    public array $writes = [];

    public ?LaiaTimeException $failure = null;
    public bool $configured = true;

    /** @param  array<string, string>  $statuses id => estado normalizado */
    public function __construct(array $statuses = [])
    {
        foreach ($statuses as $id => $status) {
            $this->add($id, $status);
        }
    }

    public function add(string $id, string $status = LaiaTimeInstance::ACTIVE): self
    {
        $this->tenants[$id] = new LaiaTimeInstance($id, "Negocio {$id}", $status, 'pro', '2026-12-01T00:00:00Z', 'etag-'.$id);

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function configurationIssue(): ?string
    {
        return $this->configured ? null : LaiaTimeException::NOT_CONFIGURED;
    }

    public function ping(): void
    {
        $this->guard();
    }

    public function instances(): array
    {
        $this->guard();

        return array_values($this->tenants);
    }

    public function instance(string $id): LaiaTimeInstance
    {
        $this->guard();

        return $this->tenants[$id] ?? throw LaiaTimeException::notFound('El negocio');
    }

    public function markPastDue(string $id): void
    {
        $this->move($id, LaiaTimeInstance::ACTIVE, LaiaTimeInstance::PAST_DUE);
    }

    public function markActive(string $id): void
    {
        $this->move($id, LaiaTimeInstance::PAST_DUE, LaiaTimeInstance::ACTIVE);
    }

    private function move(string $id, string $from, string $to): void
    {
        $current = $this->instance($id);

        if ($current->status === $to) {
            return;
        }
        if ($current->status !== $from) {
            throw LaiaTimeException::invalidState(strtoupper($current->status));
        }

        $this->tenants[$id] = $current->withStatus($to);
        $this->writes[] = "{$to}:{$id}";
    }

    private function guard(): void
    {
        if (! $this->configured) {
            throw LaiaTimeException::notConfigured();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
