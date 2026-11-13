<?php

namespace App\Services\LaiaTime;

use RuntimeException;

/**
 * Error de la integración con LaiaTime. Los mensajes están pensados para mostrarse tal cual al usuario:
 * nunca incluyen la API key (si la API remota la repitiera en un mensaje, se tacha antes de guardarlo).
 */
class LaiaTimeException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const MALFORMED_KEY = 'malformed_key';
    public const AUTH = 'auth';
    public const SCOPE = 'scope';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const RATE_LIMITED = 'rate_limited';
    public const NETWORK = 'network';
    public const INVALID_RESPONSE = 'invalid_response';
    public const REJECTED = 'rejected';
    public const INVALID_STATE = 'invalid_state';

    /** Mensaje amable para cuando la API (todavía no implementada) no responde. */
    public const UNAVAILABLE_MESSAGE = 'La API de LaiaTime todavía no está disponible o no está configurada.';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly ?string $remoteCode = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'La integración con LaiaTime no está configurada (falta la URL de la API o la API key).');
    }

    public static function malformedKey(): self
    {
        return new self(self::MALFORMED_KEY, 'La API key de LaiaTime no tiene el formato esperado (lt_live_<id>.<secreto>). Revisala en Configuración.');
    }

    public static function auth(): self
    {
        return new self(self::AUTH, 'LaiaTime rechazó las credenciales. Revisá la API key en Configuración: puede haber sido rotada o revocada.');
    }

    public static function scope(): self
    {
        return new self(self::SCOPE, 'La API key de LaiaTime no tiene los alcances necesarios. Agency OS necesita tenants:read y subscriptions:write.');
    }

    public static function notFound(string $what = 'El recurso'): self
    {
        return new self(self::NOT_FOUND, "{$what} no existe en LaiaTime.");
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT, 'Otro cambio en LaiaTime se cruzó con este (la suscripción se modificó mientras se actualizaba). Probá de nuevo.');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(
            self::RATE_LIMITED,
            "LaiaTime está limitando los pedidos de esta clave. Se reintenta solo en {$retryAfter} ".($retryAfter === 1 ? 'segundo' : 'segundos').'.',
            retryAfter: $retryAfter,
        );
    }

    public static function network(string $detail = 'No se pudo conectar con LaiaTime.'): self
    {
        return new self(self::NETWORK, $detail);
    }

    public static function invalidResponse(string $detail = 'LaiaTime respondió algo inesperado.'): self
    {
        return new self(self::INVALID_RESPONSE, $detail);
    }

    public static function rejected(string $detail, ?string $code = null, ?string $field = null): self
    {
        return new self(self::REJECTED, $detail, remoteCode: $code, field: $field);
    }

    public static function invalidState(string $currentState): self
    {
        return new self(self::INVALID_STATE, "La suscripción está en {$currentState}: Agency OS no la modifica.");
    }

    public function isNotConfigured(): bool
    {
        return $this->reason === self::NOT_CONFIGURED;
    }

    /**
     * Categoría para la interfaz:
     *   unconfigured · credentials (hay que revisar la clave) · unavailable (la API no responde o todavía no existe)
     *   rate_limited · error (cualquier otra cosa)
     */
    public function kind(): string
    {
        return match ($this->reason) {
            self::NOT_CONFIGURED => 'unconfigured',
            self::MALFORMED_KEY, self::AUTH, self::SCOPE => 'credentials',
            self::NETWORK, self::NOT_FOUND => 'unavailable',
            self::RATE_LIMITED => 'rate_limited',
            default => 'error',
        };
    }

    /** Texto para la UI de la página de estado: amable cuando la API simplemente no está disponible todavía. */
    public function friendlyMessage(): string
    {
        return $this->kind() === 'unavailable' ? self::UNAVAILABLE_MESSAGE : $this->getMessage();
    }
}
