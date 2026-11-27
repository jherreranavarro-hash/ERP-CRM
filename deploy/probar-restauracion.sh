#!/usr/bin/env bash
# Prueba de restauración: restaura el último respaldo en una base temporal, verifica
# que estén las tablas clave y que tengan filas, y borra la base. El resultado queda
# en el panel (respaldos:registrar restauracion ok|fallo); un fallo genera alerta.
# Calendario según el mantenedor (mensual por defecto): ver deploy/crontab.
# PGUSER necesita CREATEDB (ALTER ROLE erp_owner CREATEDB) o, mejor, correrlo en un
# servidor aparte con su propio PostgreSQL y el respaldo copiado desde el remoto.
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/erp}
RESPALDO_DIR=${RESPALDO_DIR:-/var/backups/erp}
BASE_PRUEBA="erp_restauracion_$(date +%Y%m%d%H%M)"
inicio=$(date +%s)

registrar() { php "$APP_DIR/artisan" respaldos:registrar restauracion "$@" || true; }
limpiar() { dropdb --if-exists "$BASE_PRUEBA" 2>/dev/null || true; }
trap 'registrar fallo --detalle="Falló en la línea $LINENO"; limpiar' ERR

ultimo=$(ls -1t "$RESPALDO_DIR"/erp-*.dump* | head -n1)
origen="$ultimo"
if [[ "$ultimo" == *.gpg ]]; then
  origen="$(mktemp)"
  gpg --batch --yes --output "$origen" --decrypt "$ultimo"
fi

createdb "$BASE_PRUEBA"
pg_restore --no-owner --exit-on-error --dbname="$BASE_PRUEBA" "$origen"

tablas=$(psql -tA -d "$BASE_PRUEBA" -c "select count(*) from information_schema.tables where table_schema = 'public'")
for t in empresas users documentos trazabilidad parametro_cambios; do
  psql -tA -d "$BASE_PRUEBA" -c "select 1 from $t limit 1" > /dev/null
done
empresas=$(psql -tA -d "$BASE_PRUEBA" -c "select count(*) from empresas")
[[ "$empresas" -ge 1 ]]

[[ "$origen" != "$ultimo" ]] && rm -f "$origen"
limpiar
trap - ERR
registrar ok --archivo="$ultimo" --duracion="$(( $(date +%s) - inicio ))" --detalle="Restaurado en base temporal: $tablas tablas, $empresas empresa(s)"
