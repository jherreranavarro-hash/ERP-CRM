# Imagen del ERP para el entorno de prueba con Docker (ver docker-compose.yml y docs/prueba-docker.md).
# Base: serversideup/php (PHP-FPM + nginx, con pdo_pgsql, redis, pcntl y opcache ya instalados).

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY vite.config.js tailwind.config.js postcss.config.js tsconfig.json ./
ENV VITE_APP_NAME="OBMAX Sistema de Gestión"
RUN npm run build

FROM serversideup/php:8.3-fpm-nginx-bookworm-v5.2.1
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_DATE_TIMEZONE=America/Santiago \
    PHP_UPLOAD_MAX_FILE_SIZE=20M \
    PHP_POST_MAX_SIZE=22M \
    NGINX_CLIENT_MAX_BODY_SIZE=22M \
    TRUSTED_PROXY=off \
    SHOW_WELCOME_MESSAGE=false

WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=frontend /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && rm -f public/hot \
    # El .env lo genera el arranque dentro del volumen de storage (compartido con colas y Reverb).
    && rm -f .env && ln -s storage/app/private/docker.env .env

COPY --chmod=755 docker/60-erp.sh /etc/entrypoint.d/60-erp.sh
COPY docker/reverb.conf /etc/nginx/server-opts.d/reverb.conf
