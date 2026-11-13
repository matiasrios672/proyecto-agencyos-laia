<?php

namespace App\Services\LaiaTime;

/**
 * Puerto de la integración con LaiaTime. Cualquier fallo se informa con {@see LaiaTimeException}.
 *
 * Principio "un dato, un dueño": Agency OS NO suspende ni reactiva nada en LaiaTime; le INFORMA el estado de pago
 * escribiendo la suscripción. LaiaTime aplica su propia gracia y pausa las reservas nuevas.
 */
interface LaiaTimeApi
{
    /** ¿Hay URL y API key cargadas? No hace ninguna llamada de red. */
    public function isConfigured(): bool;

    /** null si todo está en orden; 'not_configured' o 'malformed_key' (la clave no es `lt_live_<id>.<secreto>`). */
    public function configurationIssue(): ?string;

    /** Verifica conexión, credenciales y alcance de lectura. @throws LaiaTimeException */
    public function ping(): void;

    /**
     * Todos los negocios (recorre las páginas por cursor).
     *
     * @return list<LaiaTimeInstance>
     *
     * @throws LaiaTimeException
     */
    public function instances(): array;

    /** @throws LaiaTimeException */
    public function instance(string $id): LaiaTimeInstance;

    /**
     * Informa mora: suscripción ACTIVE → PAST_DUE. Idempotente (si ya está en mora no escribe).
     * TRIAL y CANCELED no se tocan (lanza INVALID_STATE).
     *
     * @throws LaiaTimeException
     */
    public function markPastDue(string $id): void;

    /**
     * Informa que está al día: suscripción PAST_DUE → ACTIVE. Idempotente (si ya está activa no escribe).
     * TRIAL y CANCELED no se tocan (lanza INVALID_STATE).
     *
     * @throws LaiaTimeException
     */
    public function markActive(string $id): void;
}
