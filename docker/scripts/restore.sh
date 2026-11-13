#!/bin/sh
# Agency OS - restauración de un dump generado por backup.sh.
#
#   restore.sh /backups/agency-os-20260101-030000.dump          # pide confirmación
#   restore.sh --yes /backups/agency-os-20260101-030000.dump    # sin preguntar
#   restore.sh --list                                           # lista los dumps disponibles
#
# ATENCIÓN: BORRA y reemplaza los objetos de la base DB_DATABASE (pg_restore --clean --if-exists).
# Recomendado: pausá la app (o poné RUN_QUEUE/RUN_SCHEDULER=false) mientras restaurás y hacé antes un backup.sh.
# Usa DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD (y BACKUP_DIR, por defecto /backups).
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_PREFIX="${BACKUP_PREFIX:-agency-os}"

if [ "${1:-}" = "--list" ]; then
    ls -1t "$BACKUP_DIR"/"$BACKUP_PREFIX"-*.dump 2>/dev/null || echo "(no hay dumps en $BACKUP_DIR)"
    exit 0
fi

assume_yes=false
if [ "${1:-}" = "--yes" ]; then
    assume_yes=true
    shift
fi

file="${1:-}"
[ -n "$file" ] || { echo "Uso: restore.sh [--yes] <archivo.dump> | --list" >&2; exit 2; }
[ -f "$file" ] || { echo "[restore] ERROR: no existe $file" >&2; exit 1; }

: "${DB_HOST:?Falta DB_HOST}" "${DB_DATABASE:?Falta DB_DATABASE}" "${DB_USERNAME:?Falta DB_USERNAME}" "${DB_PASSWORD:?Falta DB_PASSWORD}"
DB_PORT="${DB_PORT:-5432}"

pg_restore --list "$file" >/dev/null || { echo "[restore] ERROR: $file no es un dump válido" >&2; exit 1; }

if [ "$assume_yes" != "true" ]; then
    printf "Esto REEMPLAZA la base '%s' en %s con %s. Escribí el nombre de la base para confirmar: " "$DB_DATABASE" "$DB_HOST" "$file"
    read -r answer
    [ "$answer" = "$DB_DATABASE" ] || { echo "[restore] Cancelado."; exit 1; }
fi

echo "[restore] Restaurando $file en $DB_DATABASE@$DB_HOST:$DB_PORT ..."
PGPASSWORD="$DB_PASSWORD" pg_restore \
    --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
    --clean --if-exists --no-owner --no-privileges --exit-on-error "$file"

echo "[restore] OK. Limpiá cachés de la app si hace falta: php artisan cache:clear (los Settings y la caché viven en Redis)."
