# syntax=docker/dockerfile:1.7
#
# Agency OS - imagen de producción (Coolify).
#   docker build --target production -t laia-agencyos:prod .
#
# Etapas:  composer (vendor sin dev)  ->  node (public/build)  ->  production (PHP-FPM + nginx + Supervisor)
# Las variables VITE_* se hornean en el build (ver build args más abajo y docker-compose.yml).

# ---------------------------------------------------------------------------
# 1) composer: dependencias PHP de producción
# ---------------------------------------------------------------------------
FROM composer:2 AS composer

WORKDIR /app

# Solo los manifiestos primero: esta capa se cachea mientras no cambien las dependencias.
# --ignore-platform-reqs: la imagen de composer no trae gd/intl/etc.; la imagen final sí (verificado con php -m).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# El classmap necesita el código con autoload PSR-4 (app/, database/factories, database/seeders).
COPY app ./app
COPY database ./database
RUN composer dump-autoload --no-dev --no-scripts --optimize --classmap-authoritative --no-interaction

# ---------------------------------------------------------------------------
# 2) node: build de assets (Vite 7 + Tailwind v4)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS node

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Variables VITE_* de build (el navegador las recibe horneadas en el JS).
ARG VITE_APP_NAME="Agency OS"
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT="443"
ARG VITE_REVERB_SCHEME="https"
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm run build

# Precompresión estática (nginx gzip_static / brotli_static): cero CPU de compresión en runtime para JS/CSS.
RUN apk add --no-cache brotli \
    && find public/build -type f \( -name '*.js' -o -name '*.css' -o -name '*.svg' -o -name '*.json' \) \
        -size +512c -exec sh -c 'gzip -9 -k -f "$1" && brotli -q 11 -f "$1"' _ {} \;

# ---------------------------------------------------------------------------
# 3) production: PHP 8.3-FPM (alpine) + nginx + Supervisor
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS production

ENV TZ=America/Argentina/Buenos_Aires \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# Paquetes del sistema (nginx, supervisor, curl para healthcheck, cliente de Postgres 16 para backup/restore, zona horaria)
# y extensiones de PHP. install-php-extensions limpia sus dependencias de compilación.
RUN apk add --no-cache nginx nginx-mod-http-brotli supervisor curl tzdata postgresql16-client \
    && install-php-extensions pdo_pgsql pgsql redis gd intl zip bcmath pcntl opcache exif \
    && cp /usr/share/zoneinfo/${TZ} /etc/localtime && echo "${TZ}" > /etc/timezone \
    && rm -f /usr/local/bin/install-php-extensions \
    && rm -rf /tmp/* /var/cache/apk/* /usr/src/* ~/.cache

# Usuario no-root. Todo (php-fpm, nginx, supervisor, workers) corre como "app".
RUN addgroup -g 1000 app && adduser -D -u 1000 -G app -s /bin/sh app

# Configuración de runtime (cambia poco: va antes del código para cachear capas)
COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-agency.ini
COPY docker/prod/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/prod/nginx.conf /etc/nginx/nginx.conf
COPY docker/prod/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/scripts/backup.sh /usr/local/bin/backup.sh
COPY docker/scripts/restore.sh /usr/local/bin/restore.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/backup.sh /usr/local/bin/restore.sh \
    && mkdir -p /tmp/nginx /backups \
    && chown -R app:app /tmp/nginx /backups

WORKDIR /var/www/html

# Dependencias y código. vendor primero (cambia menos que el código de la app).
COPY --from=composer --chown=app:app /app/vendor ./vendor
# El resto del código (el .dockerignore deja afuera tests, fuentes JS/CSS, node_modules, .env, etc.).
COPY --chown=app:app . .
COPY --from=node --chown=app:app /build/public/build ./public/build

# Directorios escribibles. storage/app queda con dueño "app": un volumen nombrado nuevo hereda ese dueño.
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chown app:app public \
    && chmod -R ug+rwX storage bootstrap/cache

USER app

# Registra los paquetes (package:discover) ya con las extensiones presentes. No necesita base de datos.
RUN php artisan package:discover --ansi \
    && php artisan view:cache \
    && php artisan event:cache
# config:cache y route:cache se generan en el entrypoint (necesitan las variables de entorno reales de Coolify).

# nginx escucha en 8080 (sin privilegios). Reverb (8081) queda interno: nginx lo expone en /app y /apps.
EXPOSE 8080

# Variables por defecto de Supervisor/entrypoint (se pueden pisar desde Coolify).
ENV RUN_QUEUE=true RUN_SCHEDULER=true RUN_REVERB=true RUN_MIGRATIONS=false QUEUE_WORKERS=1

VOLUME ["/var/www/html/storage/app"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up >/dev/null || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
