#!/usr/bin/env bash
# Despliegue de una versión (correr como el usuario "erp" en /var/www/erp).
# Uso: deploy/desplegar.sh <rama-o-tag>
set -euo pipefail
cd "$(dirname "$0")/.."
ref=${1:-main}

php artisan down --retry=15 || true
trap 'php artisan up' EXIT

git fetch --tags origin
git checkout --force "$ref"
git pull --ff-only origin "$ref" 2>/dev/null || true

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

# Migraciones con el rol dueño; la app sigue corriendo con erp_app (sujeto a RLS).
php artisan migrate --database=pgsql_owner --force
php artisan db:seed --database=pgsql_owner --class=PermisosSeeder --force

php artisan optimize          # config, rutas, vistas y eventos en caché
php artisan queue:restart     # los workers toman el código nuevo al terminar su job
php artisan reverb:restart || true

php artisan sistema:verificar-produccion
