#!/bin/sh
set -e

# Automatischer Start: "docker compose up" und fertig.
# Nur der App-Container führt Init-Schritte aus (Reverb/Queue setzen ROLE!=app).

cd /var/www/html

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    echo "[entrypoint] Warte auf Datenbank ${DB_HOST}:${DB_PORT} ..."
    until php -r "exit(@fsockopen(getenv('DB_HOST'), (int)getenv('DB_PORT')) ? 0 : 1);" 2>/dev/null; do
        sleep 2
    done
    echo "[entrypoint] Datenbank erreichbar."

    # APP_KEY automatisch erzeugen, falls noch nicht gesetzt.
    if [ -z "${APP_KEY}" ] && ! grep -q "^APP_KEY=base64" .env 2>/dev/null; then
        echo "[entrypoint] Erzeuge APP_KEY ..."
        php artisan key:generate --force
    fi

    php artisan migrate --force
    php artisan storage:link || true

    if [ "${APP_ENV:-production}" = "production" ]; then
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
    fi
    echo "[entrypoint] Init abgeschlossen."
fi

exec "$@"
