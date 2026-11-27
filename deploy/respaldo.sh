#!/usr/bin/env bash
# Respaldo diario de PostgreSQL (formato custom, comprimido) con el rol dueño.
# Lo corre cron como el usuario del sistema (ver deploy/crontab). Deja constancia en
# el panel "Operación y continuidad" con php artisan respaldos:registrar.
#
# Variables (en /etc/erp/respaldo.env, permisos 600):
#   APP_DIR=/var/www/erp            directorio de la aplicación
#   RESPALDO_DIR=/var/backups/erp   destino local (disco distinto al de la base)
#   PGHOST, PGPORT, PGUSER=erp_owner, PGDATABASE=erp   y la clave en ~/.pgpass
#   RESPALDO_REMOTO=s3://bucket/erp (opcional: copia fuera del servidor con aws cli)
#   RESPALDO_GPG_DESTINATARIO=...    (opcional: cifra el archivo antes de subirlo)
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/erp}
RESPALDO_DIR=${RESPALDO_DIR:-/var/backups/erp}
inicio=$(date +%s)
archivo="$RESPALDO_DIR/erp-$(date +%Y%m%d-%H%M%S).dump"
mkdir -p "$RESPALDO_DIR"
chmod 700 "$RESPALDO_DIR"

registrar() { php "$APP_DIR/artisan" respaldos:registrar respaldo "$@" || true; }
trap 'registrar fallo --detalle="El script terminó con error en la línea $LINENO"' ERR

pg_dump --format=custom --compress=9 --no-owner --file="$archivo" "${PGDATABASE:-erp}"
pg_restore --list "$archivo" > /dev/null   # verifica que el archivo se pueda leer
chmod 600 "$archivo"

if [[ -n "${RESPALDO_GPG_DESTINATARIO:-}" ]]; then
  gpg --batch --yes --recipient "$RESPALDO_GPG_DESTINATARIO" --output "$archivo.gpg" --encrypt "$archivo"
  rm -f "$archivo"
  archivo="$archivo.gpg"
fi
if [[ -n "${RESPALDO_REMOTO:-}" ]]; then
  aws s3 cp --only-show-errors "$archivo" "$RESPALDO_REMOTO/"
fi

# Retención local según el mantenedor (mínimo 7 días).
dias=$(php "$APP_DIR/artisan" respaldos:retencion-dias 2>/dev/null) || dias=30
[[ "$dias" =~ ^[0-9]+$ ]] || dias=30
find "$RESPALDO_DIR" -maxdepth 1 -name 'erp-*.dump*' -type f -mtime +"$dias" -delete

trap - ERR
registrar ok --archivo="$archivo" --bytes="$(stat -c %s "$archivo")" --duracion="$(( $(date +%s) - inicio ))"
