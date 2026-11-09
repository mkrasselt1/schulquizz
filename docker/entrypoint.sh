#!/bin/sh
set -e

# Automatischer Start – self-contained:
#  - Ohne DB-Konfiguration nutzt die App eine INTERNE SQLite-Datenbank (kein Server nötig).
#  - Nur wenn DB_CONNECTION=mysql/pgsql UND DB_HOST gesetzt sind, wird auf die DB gewartet.
# Nur der App-Container führt Init-Schritte aus (Reverb/Queue/Scheduler setzen CONTAINER_ROLE != app).

cd /var/www/html

log() { echo "[entrypoint] $*"; }

# Fehler verständlich erklären statt nur Stacktrace – und kurz warten, damit ein
# Neustart-Loop die Logs nicht flutet.
fail() {
    log "FEHLER: $1"
    [ -n "$2" ] && log "HINWEIS: $2"
    sleep 15
    exit 1
}

# Als www-data ausführen (Dateien in storage/ sollen nie root gehören).
as_www() {
    su-exec www-data "$@"
}

KEYFILE=/var/www/html/storage/app/.appkey
REVERB_SECRET_FILE=/var/www/html/storage/app/.reverb_secret

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    # Frontend-Assets ins (ggf. als Volume gemountete) public/ kopieren – sonst bleiben nach
    # einem Image-Update die alten Assets im Volume liegen (weiße Seite).
    if [ -d /opt/public-dist ]; then
        cp -a /opt/public-dist/. public/
    fi

    # storage/-Struktur zur Laufzeit sicherstellen – wichtig, falls ein LEERER Host-Ordner
    # als Volume auf /var/www/html/storage gemountet wurde (Plesk-Persistenz).
    mkdir -p storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public
    chown -R www-data:www-data storage 2>/dev/null || true

    # APP_KEY: aus Env nehmen; sonst persistent im Storage-Volume ablegen
    # (bleibt über Neustarts stabil und ist pro Deployment eindeutig).
    if [ -z "${APP_KEY}" ] && [ ! -f "$KEYFILE" ]; then
        echo "base64:$(head -c 32 /dev/urandom | base64)" > "$KEYFILE"
        log "Neuen APP_KEY erzeugt und in $KEYFILE gesichert."
    fi

    # Reverb-Secret pro Installation (das Default im Image ist öffentlich bekannt).
    if [ -z "${REVERB_APP_SECRET}" ] || [ "${REVERB_APP_SECRET}" = "schulquizz-secret" ]; then
        if [ ! -f "$REVERB_SECRET_FILE" ]; then
            head -c 24 /dev/urandom | base64 | tr -d '/+=' > "$REVERB_SECRET_FILE"
            log "Neues Reverb-Secret erzeugt."
        fi
    fi
    chown www-data:www-data "$KEYFILE" "$REVERB_SECRET_FILE" 2>/dev/null || true
fi

# Gespeicherte Schlüssel für ALLE Rollen laden (Queue/Scheduler brauchen denselben APP_KEY).
if [ -z "${APP_KEY}" ] && [ -f "$KEYFILE" ]; then
    APP_KEY="$(cat "$KEYFILE")"
    export APP_KEY
fi
if { [ -z "${REVERB_APP_SECRET}" ] || [ "${REVERB_APP_SECRET}" = "schulquizz-secret" ]; } && [ -f "$REVERB_SECRET_FILE" ]; then
    REVERB_APP_SECRET="$(cat "$REVERB_SECRET_FILE")"
    export REVERB_APP_SECRET
fi

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    DB_CONNECTION="${DB_CONNECTION:-sqlite}"

    if [ "$DB_CONNECTION" = "sqlite" ]; then
        # Interne SQLite-DB – kein externer Datenbankserver erforderlich.
        DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
        mkdir -p "$(dirname "$DB_FILE")"
        [ -f "$DB_FILE" ] || touch "$DB_FILE"
        chown -R www-data:www-data "$(dirname "$DB_FILE")" 2>/dev/null || true
        log "Interne SQLite-Datenbank: $DB_FILE"
    elif [ -n "$DB_HOST" ]; then
        log "Warte auf Datenbank ${DB_HOST}:${DB_PORT:-3306} ..."
        i=0
        until php -r "exit(@fsockopen(getenv('DB_HOST'), (int)(getenv('DB_PORT') ?: 3306)) ? 0 : 1);" 2>/dev/null; do
            i=$((i + 1))
            if [ "$i" -ge 60 ]; then
                fail "Datenbank ${DB_HOST}:${DB_PORT:-3306} nach 120s nicht erreichbar." \
                    "Läuft der Datenbankserver? Stimmen DB_HOST/DB_PORT? Lässt die Firewall Verbindungen aus dem Docker-Netz zu?"
            fi
            sleep 2
        done
        log "Datenbank erreichbar."
    else
        log "WARNUNG: DB_CONNECTION=$DB_CONNECTION, aber kein DB_HOST gesetzt – überspringe Warten."
    fi

    # Migrationen mit Wiederholung (DB kann Port schon offen haben, aber noch initialisieren).
    tries=0
    until as_www php artisan migrate --force; do
        tries=$((tries + 1))
        if [ "$tries" -ge 5 ]; then
            fail "Datenbank-Migration fehlgeschlagen (siehe Meldung oben)." \
                "Häufige Ursachen: falsches DB-Passwort/Benutzer (Access denied), Benutzer darf sich nicht aus dem Docker-Netz anmelden, Datenbank existiert nicht."
        fi
        log "Migration fehlgeschlagen – neuer Versuch in 5s ($tries/5) ..."
        sleep 5
    done

    # Symlink public/storage → storage/app/public (public/ gehört root, daher als root).
    php artisan storage:link --force >/dev/null 2>&1 || true

    if [ "${APP_ENV:-production}" = "production" ]; then
        as_www php artisan config:cache
        as_www php artisan route:cache
        as_www php artisan view:cache
    fi

    # Sicherheitsnetz: alles, was evtl. doch als root angelegt wurde, an www-data geben.
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
    log "Init abgeschlossen."
fi

exec "$@"
