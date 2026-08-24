# Schulquizz

Datenschutzfreundliche Live-Quiz-Plattform für Lehrkräfte und Schüler:innen.
Lehrkräfte bauen Quizze im Browser und starten sie live; Schüler:innen treten **ohne Konto**
per PIN oder QR-Code bei. **Schülerantworten werden nie serverseitig gespeichert** – sie werden
nur live per WebSocket an die Lehrkraft übertragen.

Das vollständige Konzept und der Implementierungsplan stehen in
[docs/KONZEPT-UND-PLAN.md](docs/KONZEPT-UND-PLAN.md).

## Tech-Stack

Laravel 13 · Inertia + Vue 3 + TypeScript · Tailwind · Laravel Reverb (WebSockets) ·
MariaDB/SQLite · **kein Redis** (Queue/Cache über die DB) · i18n DE/EN.

---

## Variante A – Lokal ohne Docker (Entwicklung)

Voraussetzungen: PHP 8.4+, Composer, Node 20+. (Laravel 13 zieht Symfony-8-Komponenten, die PHP 8.4 verlangen.)

```bash
composer run setup     # .env anlegen, Key, Migrationen, npm install, Build (einmalig)
composer run dev       # Server + Queue + Logs + Reverb + Vite parallel
```

Danach: <http://127.0.0.1:8000>. Nutzt standardmäßig SQLite – keine DB-Installation nötig.
`composer run dev` startet auch den Reverb-WebSocket-Server, sodass Live-Funktionen laufen.

## Variante B – Docker (Betrieb / Self-Hosting)

Voraussetzung: Docker + Docker Compose. „Up und fertig":

```bash
cp .env.docker.example .env    # einmalig; Domain/Passwörter anpassen (APP_KEY kann leer bleiben)
docker compose up -d --build
```

Der App-Container wartet automatisch auf die Datenbank und führt **Key-Generierung,
Migrationen, Storage-Link und Caches** selbst aus (siehe `docker/entrypoint.sh`).
Danach ist die App unter <http://localhost:8000> erreichbar, der Reverb-WebSocket unter Port `8080`.

Container: `app` (PHP-FPM), `web` (nginx), `reverb` (WebSockets), `queue` (Worker), `mariadb`.

### DB-Persistenz ist optional

Standardmäßig speichert MariaDB in ein benanntes Volume `db-data` (bleibt über Neustarts
erhalten). Wer die Daten **nicht** persistieren will (z.B. Test-/Kiosk-Betrieb), startet mit
dem Ephemer-Override – dann liegt die DB im RAM (tmpfs) und ist nach dem Stoppen weg:

```bash
docker compose -f docker-compose.yml -f docker-compose.ephemeral.yml up -d --build
```

Eigenen Host-Ordner statt Volume nutzen (z.B. für Backups)? In `.env` setzen:

```bash
DB_DATA_PATH=./storage/mariadb    # Bind-Mount-Pfad; leer lassen = benanntes Volume
```

> Für den Produktivbetrieb einmalig ein festes `APP_KEY` setzen (`php artisan key:generate`),
> damit Sessions über Neustarts hinweg gültig bleiben, und TLS via Reverse-Proxy terminieren.

---

## Datenschutz in Kürze

Keine Schülerkonten · Antworten nur flüchtig (WebSocket → Lehrer-Browser) · keine externen
Dienste/CDNs/Tracker · vollständig self-hostbar. Details im Konzept.
