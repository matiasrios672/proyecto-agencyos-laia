<?php

namespace App\Services\LaiaTime;

/**
 * Contrato con la API de LaiaTime. Fuente: documento de diseño de LaiaTime del 2026-11-12 (la API todavía no está
 * implementada: es el contrato que se va a construir). Detalle completo para quien la implemente: docs/laiatime-contract.md
 *
 * ┌──────────────────────────────────────────────────────────────────────────────────────────────────────────┐
 * │ DEFINIDO POR EL DOCUMENTO                                                                                │
 * │   · Rutas bajo /api/v1 · Auth `Authorization: Bearer lt_live_<id>.<secreto>` (una clave por sistema)     │
 * │   · Alcances; Agency OS necesita SOLO tenants:read y subscriptions:write                                 │
 * │   · GET /tenants (paginado por CURSOR) · GET /tenants/{id} · PUT /tenants/{id}/subscription              │
 * │   · Estados de suscripción: TRIAL, ACTIVE, PAST_DUE, CANCELED                                            │
 * │   · If-Match (ETag) obligatorio en PUT; ante 412 se vuelve a leer y se reintenta UNA vez                 │
 * │   · Errores {code, message, field} · 429 con Retry-After · NO existen suspend/reactivate                 │
 * ├──────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 * │ SUPUESTO — el documento no lo define; ajustar a la doc real si difiere (todo está en ESTA clase):        │
 * │   · forma de la lista: { "data": [...], "next_cursor": "..." | null } (o un array directo, una sola página)│
 * │   · parámetros de paginación: ?cursor=...&limit=100                                                      │
 * │   · forma del tenant: { id, name, subscription: { plan, status, renewsAt } } (más variantes tolerantes)  │
 * │   · cuerpo del PUT: { "plan": ..., "status": "PAST_DUE"|"ACTIVE", "renewsAt": ... } conservando plan y   │
 * │     renewsAt tal como los devolvió el GET                                                                │
 * │   · el ETag viaja en el header de respuesta `ETag` del GET /tenants/{id}                                 │
 * └──────────────────────────────────────────────────────────────────────────────────────────────────────────┘
 */
final class LaiaTimeContract
{
    /** Todas las rutas cuelgan de este prefijo (la URL cargada en Configuración puede incluirlo o no). */
    public const API_PREFIX = '/api/v1';

    public const PATH_TENANTS = '/tenants';
    public const PATH_TENANT = '/tenants/%s';
    public const PATH_SUBSCRIPTION = '/tenants/%s/subscription';

    /** Alcances que necesita la clave de Agency OS (y nada más: mínimo privilegio). */
    public const REQUIRED_SCOPES = ['tenants:read', 'subscriptions:write'];

    /** `lt_live_<id>.<secreto>` */
    public const KEY_PATTERN = '/^lt_live_[A-Za-z0-9]+\.[A-Za-z0-9_\-]+$/';

    // SUPUESTO — paginación por cursor.
    public const PARAM_CURSOR = 'cursor';
    public const PARAM_LIMIT = 'limit';
    public const PAGE_SIZE = 100;
    /** Tope de páginas por lectura completa (evita bucles si el cursor no avanza). */
    public const MAX_PAGES = 50;

    public const HEADER_ETAG = 'ETag';
    public const HEADER_IF_MATCH = 'If-Match';

    /** Estado remoto → estado normalizado. */
    private const STATUS_MAP = [
        'TRIAL' => LaiaTimeInstance::TRIAL,
        'ACTIVE' => LaiaTimeInstance::ACTIVE,
        'PAST_DUE' => LaiaTimeInstance::PAST_DUE,
        'CANCELED' => LaiaTimeInstance::CANCELED,
        'CANCELLED' => LaiaTimeInstance::CANCELED,   // tolerancia ortográfica
    ];

    /** Estado normalizado → valor que se envía en el PUT (solo escribimos estos dos). */
    private const WRITE_STATUS = [
        LaiaTimeInstance::PAST_DUE => 'PAST_DUE',
        LaiaTimeInstance::ACTIVE => 'ACTIVE',
    ];

    public static function keyIsValid(?string $key): bool
    {
        return $key !== null && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /** Raíz de la API: acepta la URL con o sin `/api/v1` y con o sin barra final. */
    public static function baseUrl(string $configured): string
    {
        $base = rtrim(trim($configured), '/');

        return str_ends_with(strtolower($base), self::API_PREFIX) ? $base : $base.self::API_PREFIX;
    }

    public static function path(string $template, string $id): string
    {
        return sprintf($template, rawurlencode($id));
    }

    /**
     * SUPUESTO: { "data": [...], "next_cursor": string|null }. Un array directo se toma como página única.
     *
     * @param  array<mixed>  $json
     * @return array{items: list<array<string, mixed>>, next: ?string}
     */
    public static function page(array $json): array
    {
        if (array_is_list($json)) {
            return ['items' => array_values(array_filter($json, 'is_array')), 'next' => null];
        }

        $items = $json['data'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw LaiaTimeException::invalidResponse('LaiaTime devolvió una lista de negocios con un formato inesperado.');
        }

        $next = $json['next_cursor'] ?? $json['nextCursor'] ?? null;

        return ['items' => array_values(array_filter($items, 'is_array')), 'next' => is_scalar($next) && $next !== '' ? (string) $next : null];
    }

    /**
     * @param  array<mixed>  $json
     * @return array<string, mixed>
     */
    public static function unwrapOne(array $json): array
    {
        $one = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;

        if ($one === [] || array_is_list($one)) {
            throw LaiaTimeException::invalidResponse('LaiaTime devolvió un negocio con un formato inesperado.');
        }

        return $one;
    }

    /**
     * SUPUESTO: { id, name, subscription: { plan, status, renewsAt } }. También se aceptan los campos de la
     * suscripción en el primer nivel (`subscriptionStatus`, `plan`, `renewsAt`).
     * El `status` del primer nivel NO se lee: puede ser el estado operativo del tenant, no el de su suscripción.
     *
     * @param  array<string, mixed>  $data
     */
    public static function tenant(array $data, ?string $etag = null): LaiaTimeInstance
    {
        $id = self::first($data, ['id', 'tenantId', 'tenant_id', 'uuid']);
        if ($id === null || $id === '') {
            throw LaiaTimeException::invalidResponse('LaiaTime devolvió un negocio sin identificador.');
        }

        $sub = is_array($data['subscription'] ?? null) ? $data['subscription'] : [];
        $status = strtoupper((string) (self::first($sub, ['status', 'state']) ?? self::first($data, ['subscriptionStatus', 'subscription_status'])));

        return new LaiaTimeInstance(
            id: (string) $id,
            name: (string) (self::first($data, ['name', 'businessName', 'business_name', 'slug']) ?? $id),
            status: self::STATUS_MAP[$status] ?? LaiaTimeInstance::UNKNOWN,
            plan: self::nullableString(self::first($sub, ['plan']) ?? self::first($data, ['plan'])),
            renewsAt: self::nullableString(self::first($sub, ['renewsAt', 'renews_at']) ?? self::first($data, ['renewsAt', 'renews_at'])),
            etag: $etag !== null && $etag !== '' ? $etag : null,
        );
    }

    /**
     * SUPUESTO: { plan, status, renewsAt }. Se conservan plan y renewsAt exactamente como los devolvió el GET.
     *
     * @return array{plan: ?string, status: string, renewsAt: ?string}
     */
    public static function subscriptionBody(LaiaTimeInstance $current, string $targetStatus): array
    {
        $remote = self::WRITE_STATUS[$targetStatus]
            ?? throw new \InvalidArgumentException("Agency OS solo escribe ACTIVE o PAST_DUE, no {$targetStatus}.");

        return ['plan' => $current->plan, 'status' => $remote, 'renewsAt' => $current->renewsAt];
    }

    /**
     * Formato único de error de la API: { code, message, field }.
     *
     * @param  array<mixed>|null  $json
     * @return array{code: ?string, message: ?string, field: ?string}
     */
    public static function error(?array $json): array
    {
        $err = isset($json['error']) && is_array($json['error']) ? $json['error'] : ($json ?? []);

        return [
            'code' => self::nullableString($err['code'] ?? null),
            'message' => self::nullableString($err['message'] ?? null),
            'field' => self::nullableString($err['field'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function first(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
