# Contrato Agency OS ↔ LaiaTime

Fuente: documento de diseño de LaiaTime del 2026-11-12. **La API de LaiaTime todavía no está implementada**: este
archivo es el contrato que Agency OS espera, para quien la construya. Todo el mapeo vive en
`app/Services/LaiaTime/LaiaTimeContract.php`; si la API real difiere, se ajusta ahí (y en los tests).

## Principio

*Un dato, un dueño.* Agency OS **no suspende ni reactiva** nada en LaiaTime: le **informa el estado de pago**
escribiendo la suscripción (`ACTIVE` ↔ `PAST_DUE`). LaiaTime aplica su propia gracia y pausa las reservas nuevas.

## Definido por el documento de diseño

| Tema | Contrato |
| --- | --- |
| Base | `{url}/api/v1` (la URL de Configuración puede incluir o no `/api/v1`) |
| Auth | `Authorization: Bearer lt_live_<id>.<secreto>` — una clave por sistema |
| Alcances | Agency OS necesita **solo** `tenants:read` y `subscriptions:write` |
| Lectura | `GET /tenants` (paginado por **cursor**) · `GET /tenants/{id}` |
| Escritura | `PUT /tenants/{id}/subscription` |
| Estados | `TRIAL`, `ACTIVE`, `PAST_DUE`, `CANCELED` |
| Concurrencia | `If-Match` (ETag) **obligatorio** en el PUT; ante `412` se vuelve a leer y se reintenta **una** vez |
| Errores | `{ code, message, field }` |
| Límite | `429` con `Retry-After` (se espera una vez si es ≤ 10 s; si no, se informa) |
| Inexistente | No existen `suspend` / `reactivate`: se usa el estado de la suscripción |

## Supuestos (el documento no los define; ajustar si difieren)

1. Lista: `{ "data": [...], "next_cursor": "..." | null }` (un array directo se toma como página única).
2. Paginación: `?cursor=<c>&limit=100` (tope de 50 páginas por lectura).
3. Negocio: `{ id, name, subscription: { plan, status, renewsAt } }`. Se toleran variantes (`tenantId`,
   `businessName`, `subscriptionStatus`, `renews_at`…). El `status` de primer nivel **no** se lee: puede ser el estado
   operativo del negocio, no el de su suscripción.
4. Cuerpo del PUT: `{ "plan": ..., "status": "PAST_DUE" | "ACTIVE", "renewsAt": ... }`, conservando `plan` y
   `renewsAt` exactamente como los devolvió el GET.
5. El ETag viaja en el header de respuesta `ETag` del `GET /tenants/{id}`.

## Reglas de Agency OS

- **Marca automática** (`laiatime:sync`, cada hora): `ACTIVE → PAST_DUE` solo si `dunning.auto_suspend` está activo,
  el cobro pendiente más antiguo venció hace **más de** `dunning.grace_days` días y no hay prórroga manual vigente
  (`laiatime_override_until`). `TRIAL` y `CANCELED` no se tocan.
- **Reactivación automática**: `PAST_DUE → ACTIVE` solo si lo había informado el sistema
  (`laiatime_suspended_by_system`) y el cliente volvió a estar dentro de la gracia. Lo marcado a mano no se revierte solo.
- **Manual** (Dirección / sysadmin con `finance.manage`): informar mora (gana a la gracia) o reactivar; al reactivar a
  alguien que sigue en mora se le concede un nuevo plazo de gracia igual a `dunning.grace_days`.
- Idempotente: si la suscripción ya está en el estado pedido no se escribe. Una lectura de lista por corrida.
- Los fallos se guardan por cliente (`laiatime_last_error`, sin la API key) y se avisan una sola vez por falla distinta
  a Dirección y sysadmin (notificación interna).
- La API key se guarda cifrada (Configuración → LaiaTime) y nunca aparece en mensajes, logs ni respuestas.

## Comandos

```
php artisan laiatime:sync            # sincroniza ahora
php artisan laiatime:sync --dry-run  # muestra qué haría, sin escribir
```
Sin URL/API key configuradas el comando termina bien con un aviso (la integración es opcional).
