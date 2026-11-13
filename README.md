# Agency OS

Backoffice interno de **Laia Estudio Creativo Digital**: clientes, bóveda de credenciales, grillas de contenido y tableros,
presupuestos, finanzas (cobros, egresos, liquidaciones del equipo), mensajería/notificaciones y panel de administración.

## Stack

| Capa | Tecnología |
| --- | --- |
| Backend | Laravel 12, PHP 8.3 (composer fija la plataforma a 8.3), Pest para tests |
| Frontend | Inertia v2 + React 19 + TypeScript estricto + Vite 7 + Tailwind v4 |
| Datos | PostgreSQL 16 |
| Redis 7 | caché, sesiones, colas y caché de Configuración |
| Tiempo real | Laravel Reverb (WebSockets) |
| PDF | dompdf (extensión `gd`) |
| Archivos | subidas **privadas** en `storage/app` (nunca públicas) |

## Estructura de carpetas

```
app/Actions/            lógica de negocio (Finance, Quotes, ...)
app/Console/Commands/   comandos Artisan (finance:*, agency:create-sysadmin)
app/Http/Controllers/   controladores delgados (Admin, Auth, Kanban, Messaging, Security, ...)
app/Models, Policies/   modelos y políticas de autorización
config/laia.php         roles, permisos, ajustes editables de Configuración
database/               migraciones, factories y seeders
resources/js/           frontend (pages, components, layouts, lib)
routes/                 web.php + un archivo por módulo (security, content, integrations, messaging, kanban)
tests/                  Feature/Unit (Pest) y e2e/ (auditoría Playwright)
docker/                 php/ y nginx/ (desarrollo), prod/ (producción), scripts/ (backup y restore)
docker-compose.dev.yml  entorno de desarrollo
docker-compose.yml      producción (Coolify)
Dockerfile              imagen de producción (multi-stage)
.env.production.example variables de producción comentadas
```

## Módulos

Clientes (con equipo asignado y papelera) · Bóveda de credenciales (cifrada con `APP_KEY`) · Contenido (grillas con PDF y
enlaces compartibles) y tableros Kanban · Catálogo de servicios y servicios contratados · Presupuestos (PDF, envío y
conversión) · Finanzas (facturación mensual automática, cobros, egresos, liquidaciones, recordatorios de mora) ·
Equipo (invitaciones por URL firmada) · Mensajería, anuncios y notificaciones · Integración LaiaTime ·
Administración (Configuración del sistema, matriz de permisos, estado del sistema) · Seguridad (2FA, recuperación de contraseña).

## Roles y permisos

Roles (`config/laia.php`): `sysadmin` (Superadministrador, pasa por `Gate::before`: todo permitido), `dueno` (Dirección) y
`empleado`. Los permisos granulares (`clients.view`, `clients.view_all`, `clients.manage`, `vault.*`, `content.*`, `quotes.*`,
`finance.*`, `payouts.view_own`, `employees.manage`, `messages.use`) se asignan por rol y se editan desde la matriz visual
de Administración. Un empleado solo ve los clientes que tiene asignados. La autorización se aplica en el servidor (Policies y Gates).
Si la política de 2FA lo exige, Superadmin y Dirección sin 2FA solo acceden al Perfil hasta activarlo.

---

## Desarrollo en Windows + WSL2 + Docker Desktop

**El repositorio debe vivir DENTRO de WSL2** (por ejemplo `~/laia-agencyos` en Ubuntu), no en `C:\`: los bind mounts desde
Windows son muy lentos. Se edita desde Windows por `\\wsl.localhost\Ubuntu\home\<usuario>\laia-agencyos` o con VS Code Remote WSL.

Requisitos: Docker Desktop con backend WSL2 y la integración activada para tu distro.

```bash
cd ~/laia-agencyos
cp .env.example .env              # y ajustá el .env (DB_HOST=postgres, REDIS_HOST=redis, etc.)
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec app composer install
docker compose -f docker-compose.dev.yml exec app php artisan key:generate
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
docker compose -f docker-compose.dev.yml --profile frontend up -d node    # Vite con HMR
```

Servicios y puertos del host: app en <http://localhost:8080>, Mailpit en <http://localhost:8025>, PostgreSQL en `5433`,
Vite en `5173`, Reverb en `8081` (perfil `realtime`). Usuarios de demostración (solo no producción), contraseña `Laia-demo-2026`:
`admin@laia.test` (sysadmin), `direccion@laia.test` (dueño), `camila@laia.test`, `tomas@laia.test`, `julian@laia.test` (empleados).

### Comandos útiles

```bash
DC="docker compose -f docker-compose.dev.yml"
$DC exec app php artisan test                       # todos los tests
$DC exec app php artisan test --filter=VaultTest    # uno solo
$DC exec app php artisan migrate:fresh --seed       # reseed completo (BORRA la base de desarrollo)
$DC exec app ./vendor/bin/pint                      # estilo de código
$DC logs -f worker                                  # cola y scheduler
$DC --profile realtime up -d reverb                 # servidor WebSocket
$DC --profile qa run --rm playwright                # auditoría Playwright (UI), capturas en tests/e2e/screenshots
```

Chequeo de tipos del frontend (no instala nada en el repo):

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app -e HOME=/tmp -e npm_config_cache=/tmp/npm node:22-alpine npx tsc --noEmit
```

### Tests aislados

Los tests usan `RefreshDatabase`: **nunca los corras contra la base de desarrollo `laia`**. Para trabajar en paralelo o sin
riesgo, apuntá a una base y a una base de Redis propias:

```bash
docker compose -f docker-compose.dev.yml exec -T \
  -e DB_DATABASE=laia_test_x -e REDIS_DB=16 -e REDIS_CACHE_DB=16 \
  app php artisan test
```

(la base `laia_test_x` debe existir; se crea con `CREATE DATABASE` en el contenedor `postgres`).

---

## Producción con Coolify

### Qué se entrega

| Archivo | Para qué |
| --- | --- |
| `Dockerfile` | Multi-stage: `composer` (vendor sin dev) → `node` (build de Vite) → `production` (PHP 8.3-FPM alpine + nginx + Supervisor, usuario no-root) |
| `docker/prod/entrypoint.sh` | Espera a la DB, `storage:link`, `config/route/view/event:cache`, migraciones solo con `RUN_MIGRATIONS=true` |
| `docker/prod/supervisord.conf` | php-fpm, nginx, `queue:work`, `schedule:work`, `reverb:start` (logs a stdout/stderr, reinicio automático) |
| `docker/prod/nginx.conf`, `php.ini`, `php-fpm.conf` | nginx (puerto 8080, subidas hasta 30 MB), PHP (`upload_max_filesize=25M`, opcache de producción, zona horaria Buenos Aires) |
| `docker-compose.yml` | app + postgres + redis + backup, con healthchecks, límites de memoria/CPU, logs rotados y volúmenes persistentes |
| `.env.production.example` | todas las variables, comentadas |
| `docker/scripts/backup.sh`, `restore.sh` | backup/restauración de PostgreSQL (también instalados en la imagen en `/usr/local/bin`) |
| `php artisan agency:create-sysadmin` | crea el primer superadministrador |

Construir y probar la imagen localmente (sin levantar el compose de producción):

```bash
docker build --target production -t laia-agencyos:prod .
docker run --rm laia-agencyos:prod php -m
```

### Recursos y optimización (VPS chico)

**Requisitos mínimos**: 2 GB de RAM (1 vCPU alcanza) y ~10 GB de disco, **incluyendo** Coolify y el sistema. Con 1 GB de RAM
no se recomienda (agregá 1 GB de swap y bajá los límites de abajo). Con 4 GB podés subir `pm.max_children`, `shared_buffers` y los `mem_limit`.

| Servicio | `mem_limit` | CPU | Ajustes |
| --- | --- | --- | --- |
| `app` (php-fpm + nginx + queue + scheduler + reverb) | 768 MB | 1.5 | php-fpm `ondemand`, 6 hijos máx., `memory_limit` 256M, opcache 96 MB + JIT tracing 32 MB, queue `--memory=128`, nginx 1 worker |
| `postgres` | 384 MB | 1.0 | `max_connections=40`, `shared_buffers=128MB`, `effective_cache_size=384MB`, `work_mem=4MB`, `max_wal_size=512MB` |
| `redis` | 160 MB | 0.5 | `maxmemory 128mb`, `volatile-lru`, AOF `everysec` |
| `backup` | 128 MB | 0.5 | `pg_dump` diario a las 03:00 (`BACKUP_HOUR`); ~5 MB en reposo |

Total de límites ~1.4 GB (consumo real en reposo: ~400-600 MB). Todos los servicios tienen `restart: unless-stopped` y logs
`json-file` con rotación (10 MB x 3). Decisiones a tener en cuenta:

- **Un solo contenedor `app` con Supervisor** (php-fpm, nginx, queue, scheduler, reverb): evita 4 contenedores PHP duplicando
  opcache y memoria base. Si en el futuro escalás, apagá procesos con `RUN_*`.
- **Redis usa `volatile-lru` y no `allkeys-lru`**: `allkeys-lru` podría expulsar trabajos encolados (listas sin TTL).
  `volatile-lru` solo expulsa claves con TTL (caché y sesiones).
- **Sin preload de opcache**: beneficio marginal en Laravel y riesgo de romper php-fpm. El JIT se desactiva con
  `opcache.jit=disable` en `docker/prod/php.ini` si preferís ahorrar 32 MB.
- **Imagen sin secretos ni dev**: `composer --no-dev --classmap-authoritative`, sin node, assets compilados y precomprimidos
  (`.gz` y `.br`, servidos con `gzip_static`/`brotli_static` y `Cache-Control: immutable`), `view:cache` y `event:cache` en el build;
  `config:cache` y `route:cache` en el entrypoint (necesitan las variables reales). `.dockerignore` excluye `.env*`, tests, `.git`.
- **Migraciones** con `migrate --force --isolated` (lock atómico en Redis): seguras aunque arranquen dos réplicas a la vez.

### Variables de procesos (Supervisor)

`RUN_QUEUE`, `RUN_SCHEDULER`, `RUN_REVERB` (por defecto `true`) activan o no cada programa; `QUEUE_WORKERS` fija la cantidad de
workers. Si alguna vez escalás a más de una réplica de `app`, el scheduler debe correr en **una sola** (`RUN_SCHEDULER=false` en
las demás). `RUN_MIGRATIONS=true` ejecuta `migrate --force` y el seeder idempotente de roles/permisos al arrancar.
Estado de los procesos dentro del contenedor: `supervisorctl -c /etc/supervisor/supervisord.conf status`.

### Guía paso a paso

1. **Generá la APP_KEY** y guardala (ver advertencia abajo):
   `docker run --rm laia-agencyos:prod php artisan key:generate --show` (o `php artisan key:generate --show` en desarrollo).
2. **Crear el recurso**: en Coolify, *New Resource → Docker Compose* sobre este repositorio, rama de producción, archivo
   `docker-compose.yml`.
3. **Variables**: cargá las de `.env.production.example` en *Environment Variables*. Obligatorias: `APP_KEY`, `APP_URL`
   (con `https://`), `DB_PASSWORD`, `REDIS_PASSWORD`. Para tiempo real: `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`,
   `REVERB_HOST` (el mismo dominio de la app), `REVERB_PORT=443`, `REVERB_SCHEME=https`. Para correo con Resend: SMTP
   `smtp.resend.com`, usuario `resend`, contraseña = API key (también se puede cargar desde Configuración en el panel).
4. **Dominio**: en el servicio `app` asigná tu dominio con el puerto interno `8080` (`https://agency.tudominio.com:8080`
   en el campo de dominio de Coolify). Coolify/Traefik termina TLS y obtiene el certificado. La app confía en el proxy
   (`TRUSTED_PROXIES=*`) para generar URLs `https` y firmar bien las URLs firmadas.
5. **Reverb por WSS 443**: no hace falta otro dominio ni publicar el 8081. nginx dentro del contenedor reenvía
   `wss://<dominio>/app/<REVERB_APP_KEY>` y `/apps/...` al proceso Reverb (puerto interno 8081). Las `VITE_REVERB_*` se hornean
   en el build: el compose las pasa como build args a partir de las `REVERB_*`, así que **si cambiás esas variables hay que
   redesplegar** (rebuild). Sin `VITE_REVERB_APP_KEY`/`VITE_REVERB_HOST` el frontend simplemente no conecta.
6. **Primer deploy** con `RUN_MIGRATIONS=true`. En los logs vas a ver las migraciones y "Iniciando Supervisor". Después podés
   dejarlo en `true` (migra en cada deploy) o volver a `false`.
7. **Crear el superadministrador** (el seeder NO crea usuarios en producción). En la terminal del contenedor `app` (Coolify →
   Terminal), modo interactivo con contraseña oculta:
   `php artisan agency:create-sysadmin`
   o sin interacción: `php artisan agency:create-sysadmin --name="Tu Nombre" --email=vos@laia.studio --password='...'`.
   La contraseña cumple la política de la app (mínimo 10, letras y números). Es idempotente por email: volver a ejecutarlo
   con el mismo email reactiva al usuario, le deja el rol `sysadmin` y le cambia nombre y contraseña (sirve para recuperar acceso).
8. **Verificar**: `https://tu-dominio/up` responde 200; ingresá a `/login`; en Administración → Estado del sistema revisá el estado general,
   y con `supervisorctl` que los procesos estén RUNNING. El healthcheck del contenedor usa el mismo `/up`.
9. **Programar backups**: el servicio `backup` ya hace un dump diario; configurá la copia fuera del VPS (sección siguiente).
10. **Cron / scheduler**: no hace falta cron en Coolify; `schedule:work` corre dentro de `app` (`RUN_SCHEDULER=true`).
11. **WebSocket**: si Traefik/Cloudflare está delante, asegurá que permita WebSockets en `/app` (Traefik lo hace por defecto;
    en Cloudflare activá *WebSockets*). Probá `wss://<dominio>/app/<REVERB_APP_KEY>` desde la consola del navegador.

### Qué revisar si falla

- *El deploy falla con "required variable ... is missing"*: falta una variable obligatoria en Coolify.
- *Contenedor `app` en bucle de reinicio*: mirá los logs. `APP_KEY está vacía` o `la base de datos no respondió` (revisá
  `DB_*` y que `postgres` esté healthy) son los mensajes del entrypoint. `storage no es escribible`: el volumen `storage_app`
  existente tiene otro dueño; corregilo con `chown -R 1000:1000` sobre el volumen.
- *Enlaces https mal generados o "invalid signature"*: `APP_URL` debe ser `https://...` y `TRUSTED_PROXIES` no debe excluir a Traefik.
- *Cookies/sesión que no persisten*: `SESSION_SECURE_COOKIE=true` exige acceder por https.
- *No llegan correos*: revisá `MAIL_*` o Configuración → Correo, y que el remitente pertenezca a un dominio verificado en Resend.
  La cola debe estar corriendo (`RUN_QUEUE=true`).
- *Tiempo real no conecta*: verificá `REVERB_*`, que hayas **redesplegado** tras cambiarlas y que `RUN_REVERB=true`.
  Nota: el servidor publica eventos a Reverb usando `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME`, es decir por el dominio público.
- *Subidas rechazadas*: el límite es 25 MB por archivo (nginx 30M, PHP 25M/30M); si hay otro proxy delante, ajustalo también.
- *Tareas programadas no corren*: `RUN_SCHEDULER=true` en una réplica; mirá los logs de `scheduler`.

### Backups

> ## ADVERTENCIA: respaldá la `APP_KEY`
> **Si se pierde la `APP_KEY` (o se cambia), se pierde para siempre la bóveda de credenciales de los clientes, los secretos
> de Configuración (SMTP, LaiaTime, etc.) y los secretos de 2FA.** Los datos quedan en la base pero no se pueden descifrar y
> no hay recuperación. Guardala en un gestor de contraseñas **fuera del VPS** el mismo día que la generás, y nunca la rotes sin un plan.

**PostgreSQL** (la base es la fuente de verdad; Redis es solo caché/colas/sesiones):

- `docker/scripts/backup.sh` hace `pg_dump` en formato custom comprimido en `/backups` (volumen `backups`), verifica el
  archivo y rota: borra los dumps con más de `BACKUP_KEEP_DAYS` (14) días conservando siempre los `BACKUP_MIN_KEEP` (3) más nuevos.
- **Ya está programado**: el servicio `backup` del compose corre `backup.sh` todos los días a las 03:00 (hora `TZ`; cambiala con
  `BACKUP_HOUR`). Si preferís el cron de Coolify, quitá ese servicio y creá en *Scheduled Tasks*: comando `backup.sh`,
  contenedor `app`, frecuencia `0 3 * * *`. Ejecución manual: terminal del contenedor `app` → `backup.sh`.
- El volumen `backups` está en el mismo VPS: **copiá los dumps fuera** (S3/Backblaze con el volume backup de Coolify, `rclone`,
  o descarga periódica). Alternativa: si preferís usar los *backups programados* integrados de Coolify con destino S3, creá
  PostgreSQL como recurso de base de datos de Coolify y apuntá `DB_HOST` a él.
- Restaurar: `restore.sh --list` y `restore.sh /backups/agency-os-AAAAMMDD-HHMMSS.dump` (pide escribir el nombre de la base;
  `--yes` para omitirlo). **Reemplaza** el contenido de la base. Pausá la app antes y limpiá la caché después
  (`php artisan cache:clear`).
- Probá una restauración en un entorno aparte al menos una vez.

**Archivos subidos (PDFs)**: viven en el volumen `storage_app` (`/var/www/html/storage/app`). Respaldalo con el *volume backup*
de Coolify o copiándolo periódicamente (`docker run --rm -v <volumen>:/data -v $PWD:/out alpine tar czf /out/storage-app.tgz -C /data .`).
Sin ese volumen, los PDF se pierden en cada redeploy.
