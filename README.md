# Schulquizz

Datenschutzfreundliche Quiz- und Escape-Room-Plattform für Lehrkräfte und Schüler:innen.

- **Live-Quiz:** Lehrkräfte bauen Quizze im Browser und starten sie live; die Lehrkraft schaltet
  Frage für Frage weiter.
- **Escape-Room:** Räume mit Hintergrundbild und klickbaren Hotspots (Hinweise, Rätsel, Schlösser);
  Teams spielen im eigenen Tempo, die Lehrkraft sieht den Fortschritt live.
- **KI-Assistenten (MCP):** Rätsel und Räume per Chat anlegen (z. B. mit Claude), Anmeldung per OAuth.

Schüler:innen treten **ohne Konto** per PIN oder QR-Code bei. **Schülerantworten werden nie
serverseitig gespeichert** – sie gehen nur live per WebSocket an die Lehrkraft. Details:
[PRIVACY.md](PRIVACY.md). Was sich wann geändert hat: [CHANGELOG.md](CHANGELOG.md).

Konzept und ursprünglicher Plan: [docs/KONZEPT-UND-PLAN.md](docs/KONZEPT-UND-PLAN.md),
Umsetzungsentscheidungen: [docs/ENTSCHEIDUNGEN.md](docs/ENTSCHEIDUNGEN.md).

## Tech-Stack

Laravel 13 · Inertia + Vue 3 + TypeScript · Tailwind · Laravel Reverb (WebSockets) ·
Laravel Passport (OAuth) + laravel/mcp · MariaDB/SQLite · **kein Redis** (Queue/Cache über die DB) ·
i18n DE/EN.

---

## Variante A – Ein Container (empfohlen, z. B. Plesk)

Fertiges Image von Docker Hub: **`mkrasselt1/schulquizz`** – Tags `latest` (neueste Version) oder
eine feste Version wie `dev-0.23` (siehe [CHANGELOG.md](CHANGELOG.md)). Im Container laufen
Webserver, PHP, WebSocket-Server, Queue und Scheduler; ohne weitere Angaben nutzt er eine interne
SQLite-Datenbank.

**1. Volume einrichten (wichtig!)** – sonst sind bei jedem Update Bilder, Schlüssel und (bei SQLite)
alle Daten weg:

| Host-Pfad (frei wählbar) | Pfad im Container |
|---|---|
| `/var/docker/schulquizz/storage` | `/var/www/html/storage` |

Bei Umzug eines bestehenden Containers ohne Volume vorher sichern:

```bash
mkdir -p /var/docker/schulquizz/storage
docker cp <container>:/var/www/html/storage/. /var/docker/schulquizz/storage/
chown -R 82:82 /var/docker/schulquizz/storage   # 82 = www-data im Image
```

**2. Port:** Container-Port `80` auf einen Host-Port legen und per Reverse-Proxy (Plesk) mit HTTPS
ausliefern. WebSockets laufen über denselben Port (Pfad `/app`) – im Proxy muss WebSocket-Support
aktiv sein.

**3. Umgebungsvariablen** (mindestens):

| Variable | Beispiel | Hinweis |
|---|---|---|
| `APP_URL` | `https://quiz.meine-schule.de` | **mit `https://`**, ohne `/` am Ende |
| `DB_CONNECTION` … | siehe unten | nur für externe MariaDB nötig |
| `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | | sonst verschickt „Passwort vergessen“ nichts |
| `REGISTRATION_CODE` | `lehrerzimmer` | optional: Registrieren nur mit Code |
| `REGISTRATION_ENABLED` | `false` | optional: Registrierung ganz aus |

`APP_KEY`, das Reverb-Secret und die OAuth-Schlüssel erzeugt der Container beim ersten Start selbst
und legt sie im Volume ab.

**Externe MariaDB** (z. B. die von Plesk): `DB_CONNECTION=mysql`, `DB_HOST` (vom Container aus
erreichbar, z. B. die Docker-Bridge `172.17.0.1`), `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`. Der Datenbankbenutzer muss Verbindungen aus dem Docker-Netz erlauben (z. B. Host
`172.17.%`), und Port 3306 darf **nicht** aus dem Internet erreichbar sein.

**Update:** neues Image ziehen (`latest` aktualisieren oder neue Version wählen) und den Container
**neu erstellen** (ein Neustart allein nutzt das alte Image). Migrationen laufen automatisch.

**Betrieb:**
- Gesundheitscheck eingebaut (Web + WebSocket); abgestürzte Dienste startet der Container neu.
- Logs: im Container-Log (Plesk → Docker → Container → Log); zusätzlich `storage/logs`, 14 Tage.
- Stündliches Aufräumen: verwaiste Spiele (8 h ohne Aktivität) beenden, beendete Spiele nach
  30 Tagen, unbenutzte Bilder, abgelaufene Cache-Einträge löschen.
- Backup: den Volume-Ordner sichern (bei externer MariaDB zusätzlich die Datenbank).

## Variante B – Docker Compose (mehrere Container)

```bash
cp .env.docker.example .env    # Domain/Passwörter anpassen (APP_KEY kann leer bleiben)
docker compose up -d --build                         # selbst bauen
docker compose -f docker-compose.hub.yml up -d       # oder fertiges Image von Docker Hub
```

Container: `app` (PHP-FPM), `web` (nginx), `reverb` (WebSockets), `queue` (Worker),
`scheduler` (Aufräumen), `mariadb`. App unter <http://localhost:8000>, Reverb unter Port `8080`.

MariaDB speichert in das benannte Volume `db-data`. Ohne Persistenz (Test/Kiosk):

```bash
docker compose -f docker-compose.yml -f docker-compose.ephemeral.yml up -d --build
```

Eigener Host-Ordner statt Volume: in `.env` `DB_DATA_PATH=./storage/mariadb` setzen.

## Variante C – Lokal ohne Docker (Entwicklung)

Voraussetzungen: PHP 8.4+, Composer, Node 20+.

```bash
composer run setup     # .env anlegen, Key, Migrationen, npm install, Build (einmalig)
composer run dev       # Server + Queue + Logs + Reverb + Vite parallel
php artisan passport:keys   # einmalig, für die MCP-/OAuth-Schnittstelle
```

Danach: <http://127.0.0.1:8000> (SQLite, keine DB-Installation nötig). Tests: `php artisan test`.

---

## KI-Assistenten (MCP-Schnittstelle)

Lehrkräfte können einen KI-Assistenten (z. B. Claude, ChatGPT, Cursor) verbinden, um Rätsel und
Escape-Räume per Chat anzulegen und zu bearbeiten. Adresse: `https://<eure-domain>/mcp`
(steht auch im Profil zum Kopieren).

- Anmeldung per **OAuth 2.1**: Der Assistent registriert sich selbst, die Lehrkraft meldet sich an
  und klickt „Erlauben“. Kein Token-Kopieren.
- Werkzeuge: Spiele auflisten/ansehen/anlegen, Rätsel anlegen/ändern/löschen, Escape-Räume festlegen.
- Zugriff nur auf **eigene** Spiele – keine Schülerdaten, keine Live-Spiele, keine Bild-Uploads.
- Widerrufen: Profil → „KI-Assistenten (MCP)“ → „Zugriff entziehen“.

## Datenschutz in Kürze

Keine Schülerkonten · Antworten nur flüchtig (WebSocket → Lehrer-Browser) · keine externen
Dienste/CDNs/Tracker · vollständig self-hostbar. Details in [PRIVACY.md](PRIVACY.md).
