#!/bin/sh
# Agency OS - backup de PostgreSQL (pg_dump formato custom, comprimido) con rotación.
#
# Usa las mismas variables que la app: DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD.
# Opcionales:
#   BACKUP_DIR        destino (por defecto /backups, volumen "backups" del docker-compose.yml)
#   BACKUP_KEEP_DAYS  días de retención (por defecto 14)
#   BACKUP_MIN_KEEP   cantidad mínima de dumps que NUNCA se borran por antigüedad (por defecto 3)
#   BACKUP_PREFIX     prefijo del archivo (por defecto agency-os)
#
# Dentro del contenedor app:  backup.sh      (queda instalado en /usr/local/bin)
# Salida: $BACKUP_DIR/<prefijo>-AAAAMMDD-HHMMSS.dump
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
BACKUP_MIN_KEEP="${BACKUP_MIN_KEEP:-3}"
BACKUP_PREFIX="${BACKUP_PREFIX:-agency-os}"

: "${DB_HOST:?Falta DB_HOST}" "${DB_DATABASE:?Falta DB_DATABASE}" "${DB_USERNAME:?Falta DB_USERNAME}" "${DB_PASSWORD:?Falta DB_PASSWORD}"
DB_PORT="${DB_PORT:-5432}"

mkdir -p "$BACKUP_DIR"
[ -w "$BACKUP_DIR" ] || { echo "[backup] ERROR: $BACKUP_DIR no es escribible" >&2; exit 1; }

stamp="$(date +%Y%m%d-%H%M%S)"
final="$BACKUP_DIR/$BACKUP_PREFIX-$stamp.dump"
tmp="$final.partial"
trap 'rm -f "$tmp"' EXIT

echo "[backup] Volcando $DB_DATABASE@$DB_HOST:$DB_PORT -> $final"
PGPASSWORD="$DB_PASSWORD" pg_dump \
    --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
    --format=custom --compress=9 --no-owner --no-privileges \
    --file="$tmp"

# Verificación: archivo no vacío y legible por pg_restore.
[ -s "$tmp" ] || { echo "[backup] ERROR: el dump quedó vacío" >&2; exit 1; }
pg_restore --list "$tmp" >/dev/null || { echo "[backup] ERROR: el dump no es válido" >&2; exit 1; }

mv "$tmp" "$final"
trap - EXIT
echo "[backup] OK ($(du -h "$final" | cut -f1))"

# Rotación: borra dumps más viejos que BACKUP_KEEP_DAYS, conservando siempre los BACKUP_MIN_KEEP más nuevos.
i=0
for f in $(ls -1t "$BACKUP_DIR"/"$BACKUP_PREFIX"-*.dump 2>/dev/null); do
    i=$((i + 1))
    if [ "$i" -le "$BACKUP_MIN_KEEP" ]; then
        continue
    fi
    if [ -n "$(find "$f" -mtime +"$BACKUP_KEEP_DAYS" 2>/dev/null)" ]; then
        echo "[backup] Rotación: borrando $f"
        rm -f "$f"
    fi
done

echo "[backup] Dumps actuales: $(ls -1 "$BACKUP_DIR"/"$BACKUP_PREFIX"-*.dump 2>/dev/null | wc -l)"
