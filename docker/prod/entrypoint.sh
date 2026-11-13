#!/bin/sh
# Agency OS - entrypoint de producción.
# Solo prepara la app cuando el comando es supervisord (arranque normal). Cualquier otro comando
# (php -v, php artisan ..., sh) se ejecuta tal cual, sin esperar la base ni tocar cachés.
set -eu

log() { echo "[entrypoint] $*"; }

if [ "${1:-}" != "supervisord" ]; then
    exec "$@"
fi

cd /var/www/html

# --- Valores por defecto (Coolify puede pisarlos) ---
export RUN_QUEUE="${RUN_QUEUE:-true}"
export RUN_SCHEDULER="${RUN_SCHEDULER:-true}"
export RUN_REVERB="${RUN_REVERB:-true}"
export QUEUE_WORKERS="${QUEUE_WORKERS:-1}"
export REVERB_SERVER_HOST="${REVERB_SERVER_HOST:-0.0.0.0}"
export REVERB_SERVER_PORT="${REVERB_SERVER_PORT:-8081}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-false}"
DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-90}"

# --- Chequeos duros ---
if [ -z "${APP_KEY:-}" ]; then
    log "ERROR: APP_KEY está vacía. Generala con 'php artisan key:generate --show' y guardala en Coolify (y en un lugar seguro: si se pierde, se pierde la bóveda)."
    exit 1
fi

if [ ! -w storage/app ] || [ ! -w storage/logs ] || [ ! -w bootstrap/cache ]; then
    log "ERROR: storage/ o bootstrap/cache no son escribibles por el usuario $(id -un) (uid $(id -u))."
    log "       Si montaste un volumen/bind mount que ya existía con otro dueño, corregilo: chown -R 1000:1000 <volumen>"
    exit 1
fi

mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
         storage/framework/sessions storage/framework/views storage/logs

# --- Esperar a PostgreSQL ---
log "Esperando a la base de datos (${DB_HOST:-?}:${DB_PORT:-5432}), máx ${DB_WAIT_TIMEOUT}s..."
waited=0
until php -r '
    try {
        new PDO(
            sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE")),
            getenv("DB_USERNAME"), getenv("DB_PASSWORD"),
            [PDO::ATTR_TIMEOUT => 3]
        );
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
' 2>/dev/null; do
    waited=$((waited + 2))
    if [ "$waited" -ge "$DB_WAIT_TIMEOUT" ]; then
        log "ERROR: la base de datos no respondió en ${DB_WAIT_TIMEOUT}s. Revisá DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD."
        exit 1
    fi
    sleep 2
done
log "Base de datos lista."

# --- Enlace de storage público y cachés de framework ---
php artisan storage:link --force >/dev/null 2>&1 || log "AVISO: no se pudo crear public/storage (se ignora; las subidas son privadas)."

log "Generando cachés (config, route; view y event ya vienen compilados en la imagen)..."
php artisan config:cache
php artisan route:cache
# Si la imagen no trae las cachés de view/event (build antiguo), se regeneran (barato si ya existen).
php artisan view:cache >/dev/null 2>&1 || true
php artisan event:cache >/dev/null 2>&1 || true

# --- Migraciones: solo si se pide explícitamente ---
if [ "$RUN_MIGRATIONS" = "true" ]; then
    log "RUN_MIGRATIONS=true: ejecutando migraciones (--isolated: lock atómico en la caché Redis; evita carreras entre réplicas)..."
    php artisan migrate --force --isolated
    # Roles y permisos (idempotente: findOrCreate; no pisa la matriz ya editada).
    php artisan db:seed --class=RolesAndPermissionsSeeder --force
else
    log "RUN_MIGRATIONS!=true: se omiten migraciones."
fi

log "Iniciando Supervisor (queue=${RUN_QUEUE}, scheduler=${RUN_SCHEDULER}, reverb=${RUN_REVERB})."
exec "$@"
