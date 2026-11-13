<?php

namespace App\Services\LaiaTime;

/**
 * Un tenant (negocio) de LaiaTime con su estado de suscripción, ya normalizado.
 * Estados remotos TRIAL / ACTIVE / PAST_DUE / CANCELED → trial / active / past_due / canceled.
 */
final readonly class LaiaTimeInstance
{
    public const TRIAL = 'trial';
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';
    public const CANCELED = 'canceled';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?string $plan = null,
        public ?string $renewsAt = null,
        /** ETag de la última lectura (hace falta como If-Match para escribir). */
        public ?string $etag = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isPastDue(): bool
    {
        return $this->status === self::PAST_DUE;
    }

    public function withStatus(string $status): self
    {
        return new self($this->id, $this->name, $status, $this->plan, $this->renewsAt, $this->etag);
    }
}
