# syntax=docker/dockerfile:1

# ---------- Stage 1: PHP dependencies ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress

# ---------- Stage 2: Frontend build ----------
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js tsconfig.json tailwind.config.js postcss.config.js ./
COPY public ./public
# Ziggy (route()-Helper) wird vom Frontend-Build gebraucht und liegt im Composer-Vendor.
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN npm run build

# ---------- Stage 3: Runtime (PHP-FPM) ----------
# PHP 8.4, passend zur composer.lock (Dependencies verlangen >= 8.4.1).
FROM php:8.4-fpm-alpine AS app
WORKDIR /var/www/html

# System + PHP extensions (pdo_mysql for MariaDB, pcntl for Reverb, gd/zip for images/export)
RUN apk add --no-cache libpng-dev libzip-dev icu-dev oniguruma-dev su-exec \
    && docker-php-ext-configure gd \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl gd zip intl bcmath opcache \
    && rm -rf /var/cache/apk/*

# Upload-Limits (Handyfotos), Opcache
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-schulquizz.ini

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache database \
    && touch database/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && cp -a public /opt/public-dist \
    && cp docker/entrypoint.sh /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------- Stage 4: Standalone (alles in EINEM Container) ----------
# nginx + php-fpm + Reverb + Queue + interne SQLite-DB.
# "docker run -p 8000:80 <image>" → läuft ohne weitere Konfiguration.
FROM app AS standalone

RUN apk add --no-cache nginx supervisor \
    && rm -f /etc/nginx/http.d/default.conf

COPY docker/nginx.standalone.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# Self-contained-Defaults: interne SQLite-DB, Reverb lokal, alles über die DB (kein Redis).
ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/database.sqlite \
    QUEUE_CONNECTION=database \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    BROADCAST_CONNECTION=reverb \
    REVERB_APP_ID=schulquizz \
    REVERB_APP_KEY=schulquizz \
    REVERB_APP_SECRET=schulquizz-secret \
    REVERB_SERVER_HOST=0.0.0.0 \
    REVERB_SERVER_PORT=8080 \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8080 \
    REVERB_SCHEME=http \
    REVERB_MAX_REQUEST_SIZE=2000000 \
    LOG_CHANNEL=stack \
    LOG_STACK=stderr,daily \
    LOG_DAILY_DAYS=14

# Alle persistenten Daten (SQLite-DB, APP_KEY, Uploads) liegen in storage/.
# → In Plesk NUR diesen einen Ordner als Volume mappen, dann ist alles persistent.
# /var/www/html/database bleibt im Image (enthält Migrationen – NICHT mounten!).
VOLUME ["/var/www/html/storage"]

EXPOSE 80

# Gesundheitscheck: Web (Laravel /up) UND WebSocket-Server (Reverb) müssen antworten.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/up && nc -z 127.0.0.1 8080 || exit 1

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
