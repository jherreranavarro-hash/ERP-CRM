#!/bin/sh
# Arranque del ERP en Docker. Solo el contenedor "app" (ERP_ROL=app) prepara todo:
# genera secretos una vez, escribe el .env, migra y carga la demo. Colas, programador
# y Reverb esperan a que "app" esté sano y leen el mismo .env desde el volumen.
set -e
[ "$ERP_ROL" = "app" ] || exit 0
cd /var/www/html

DIR=storage/app/private
mkdir -p "$DIR" storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
azar() { php -r "echo rtrim(strtr(base64_encode(random_bytes($1)), '+/', 'AB'), '=');"; }
if [ ! -f "$DIR/secretos-docker.env" ]; then
    umask 077
    {
        echo "APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        echo "REVERB_APP_SECRET=$(azar 24)"
        echo "CONFIG_EXPORT_KEY=$(azar 32)"
        echo "PBX_WEBHOOK_SECRET=$(azar 24)"
        echo "WHATSAPP_APP_SECRET=$(azar 24)"
        echo "WHATSAPP_VERIFY_TOKEN=$(azar 24)"
    } > "$DIR/secretos-docker.env"
fi

umask 077
cat > "$DIR/docker.env" <<ENV
APP_NAME="OBMAX Sistema de Gestión"
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=America/Santiago
APP_URL=${APP_URL:-http://localhost:8000}
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_FAKER_LOCALE=es_CL
LOG_CHANNEL=stderr
LOG_LEVEL=info
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=erp
DB_USERNAME=erp_app
DB_PASSWORD=${ERP_APP_PASSWORD}
DB_OWNER_USERNAME=erp_owner
DB_OWNER_PASSWORD=${ERP_OWNER_PASSWORD}
SESSION_DRIVER=redis
SESSION_LIFETIME=720
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-responder@obramax.cl"
MAIL_FROM_NAME="OBMAX Sistema de Gestión"
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=erp-local
REVERB_APP_KEY=erp-local
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_PUBLIC_HOST=${REVERB_PUBLIC_HOST:-localhost}
REVERB_PUBLIC_PORT=${REVERB_PUBLIC_PORT:-8000}
REVERB_PUBLIC_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
ENV
cat "$DIR/secretos-docker.env" >> "$DIR/docker.env"

echo "ERP: esperando la base de datos..."
until php -r 'try { new PDO("pgsql:host=db;dbname=erp", "erp_owner", getenv("ERP_OWNER_PASSWORD")); } catch (Throwable $e) { exit(1); }' 2>/dev/null; do sleep 2; done

php artisan config:clear >/dev/null
php artisan migrate --database=pgsql_owner --force
php artisan db:seed --database=pgsql_owner --force

EMPRESAS=$(php -r '$p = new PDO("pgsql:host=db;dbname=erp", "erp_owner", getenv("ERP_OWNER_PASSWORD")); echo $p->query("select count(*) from empresas")->fetchColumn();')
if [ "$EMPRESAS" = "0" ]; then
    echo "ERP: cargando datos de demostración..."
    php artisan db:seed --database=pgsql_owner --class=DemoSeeder --force
fi

php artisan optimize

echo "=================================================================="
echo " ERP listo en ${APP_URL:-http://localhost:8000}"
echo " Usuarios demo: admin@obramax.cl y caja@obramax.cl"
echo " Contraseña: DEMO_PASSWORD de docker-compose.yml (por defecto ObramaxDemo2026)"
echo "=================================================================="
