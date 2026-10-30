#!/bin/sh
set -e

# Automatischer Start – self-contained:
#  - Ohne DB-Konfiguration nutzt die App eine INTERNE SQLite-Datenbank (kein Server nötig).
#  - Nur wenn DB_CONNECTION=mysql/pgsql UND DB_HOST gesetzt sind, wird auf die DB gewartet.
# Nur der App-Container führt Init-Schritte aus (Reverb/Queue setzen CONTAINER_ROLE != app).

cd /var/www/html

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    # storage/-Struktur zur Laufzeit sicherstellen – wichtig, falls ein LEERER Host-Ordner
    # als Volume auf /var/www/html/storage gemountet wurde (Plesk-Persistenz).
    mkdir -p storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public
    chown -R www-data:www-data storage 2>/dev/null || true

    DB_CONNECTION="${DB_CONNECTION:-sqlite}"

    if [ "$DB_CONNECTION" = "sqlite" ]; then
        # Interne SQLite-DB – kein externer Datenbankserver erforderlich.
        DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
        mkdir -p "$(dirname "$DB_FILE")"
        [ -f "$DB_FILE" ] || touch "$DB_FILE"
        chown -R www-data:www-data "$(dirname "$DB_FILE")" 2>/dev/null || true
        echo "[entrypoint] Interne SQLite-Datenbank: $DB_FILE"
    elif [ -n "$DB_HOST" ]; then
        echo "[entrypoint] Warte auf Datenbank ${DB_HOST}:${DB_PORT:-3306} ..."
        i=0
        until php -r "exit(@fsockopen(getenv('DB_HOST'), (int)(getenv('DB_PORT') ?: 3306)) ? 0 : 1);" 2>/dev/null; do
            i=$((i + 1))
            if [ "$i" -ge 60 ]; then
                echo "[entrypoint] FEHLER: Datenbank ${DB_HOST}:${DB_PORT:-3306} nach 120s nicht erreichbar."
                php -r '$f=@fsockopen(getenv("DB_HOST"),(int)(getenv("DB_PORT")?:3306),$no,$err,5); echo "[entrypoint] Grund: ".($f?"jetzt erreichbar":"$err (errno $no)")."\n";' || true
                echo "[entrypoint] Hinweis: Läuft MariaDB auf dem Docker-Host, muss sie auf 172.17.0.1/0.0.0.0 lauschen"
                echo "[entrypoint]          (bind-address), die Firewall docker0 erlauben und der DB-User von 172.17.% zugreifen dürfen."
                echo "[entrypoint]          Alternativ DB_CONNECTION weglassen → interne SQLite-DB."
                exit 1
            fi
            sleep 2
        done
        echo "[entrypoint] Datenbank erreichbar."
    else
        echo "[entrypoint] WARNUNG: DB_CONNECTION=$DB_CONNECTION, aber kein DB_HOST gesetzt – überspringe Warten."
    fi

    # APP_KEY: aus Env nehmen; sonst persistent im Storage-Volume ablegen
    # (bleibt über Neustarts stabil und ist pro Deployment eindeutig).
    if [ -z "${APP_KEY}" ]; then
        KEYFILE=/var/www/html/storage/app/.appkey
        if [ -f "$KEYFILE" ]; then
            APP_KEY="$(cat "$KEYFILE")"
        else
            APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
            mkdir -p "$(dirname "$KEYFILE")"
            echo "$APP_KEY" > "$KEYFILE"
            chown www-data:www-data "$KEYFILE" 2>/dev/null || true
            echo "[entrypoint] Neuen APP_KEY erzeugt und in $KEYFILE gesichert."
        fi
        export APP_KEY
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
