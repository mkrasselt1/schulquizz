# syntax=docker/dockerfile:1

# ---------- Stage 1: Frontend build ----------
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.ts tsconfig.json ./
COPY public ./public
RUN npm run build

# ---------- Stage 2: PHP dependencies ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress

# ---------- Stage 3: Runtime (PHP-FPM) ----------
FROM php:8.3-fpm-alpine AS app
WORKDIR /var/www/html

# System + PHP extensions (pdo_mysql for MariaDB, pcntl for Reverb, gd/zip for images/export)
RUN apk add --no-cache libpng-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl gd zip intl bcmath \
    && rm -rf /var/cache/apk/*

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache \
    && cp docker/entrypoint.sh /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
