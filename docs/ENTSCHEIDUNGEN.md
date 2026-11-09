# Autonome Entscheidungen (Umsetzung)

Dieses Log dokumentiert alle Entscheidungen, die ich während der eigenständigen Umsetzung
(Phasen 2–8) „im Sinne des Auftraggebers" getroffen habe. Jede Entscheidung mit kurzer Begründung,
damit du sie morgen schnell prüfen/revidieren kannst.

Leitplanken aus dem Konzept: Datensparsamkeit (keine Schülerdaten in DB), kein Redis,
self-hostbar, DE/EN, wiederverwendbare Layout-Bausteine.

---

## Phase 0/1 (bereits abgestimmt, hier nur festgehalten)
- **Laravel 13** statt 11 (neueste Version, sonst wie geplant).
- **SQLite** als lokale Dev-DB (null Setup); **MariaDB** im Docker.
- **Übersetzbare Inhalte**: eigener, abhängigkeitsfreier `HasTranslations`-Trait (JSON locale=>value)
  statt externer Lib – weniger Abhängigkeiten, volle Kontrolle.
- **join_token**: 10-stellig, lowercase, pro Spiel unique → feste QR-URL `/j/{token}`.

---

## Phase 2 – Editor & Fragetypen

- **8 Fragetypen** decken alle Konzept-Anforderungen ab: `choice` (Einzel-/Mehrfachauswahl über
  `config.multiple`, Antworten Text **oder** Bild, Randomisierung über `config.randomize`), `cloze`,
  `free_text`, `draw`, `sort`, `poll_slider`, `estimate`, `word_cloud`. Statt separater Typen für
  Einzel/Mehrfach ein Flag – weniger Redundanz.
- **Punkte-Default 1000** (Kahoot-typisch, gut für Schnelligkeits-Skalierung). Ungewertete Typen
  (`poll_slider`, `word_cloud`) erzwingen `scored=false`, `points=0`.
- **`reveal_after` je Frage ist `null`-fähig** = „erbt Spiel-Default"; nur true/false überschreibt.
- **Antworten-Sync** beim Speichern: vorhandene per ID aktualisieren, neue anlegen, entfernte löschen
  (eine transaktionale PUT statt vieler Endpunkte). Reihenfolge = Array-Index.
- **Bilder** lokal auf der `public`-Disk unter `game-images/{gameId}/` (keine externen Dienste);
  Upload liefert nur den Pfad zurück. Limit 5 MB, Formate png/jpg/webp/gif.
- **Export/Import** bettet Bilder als Base64 mit ins `.quiz.json` – Spiele bleiben vollständig
  portabel/teilbar; Import legt Bilder neu unter der Kopie ab.
- **Übersetzungen**: Editor bearbeitet nur die `base_locale`; bereits vorhandene andere Sprachen
  bleiben beim Speichern erhalten (Merge statt Überschreiben). Kein Übersetzungs-UI (wie vereinbart).
- **Layout-Bausteine** eingeführt und ab hier überall genutzt: `ui/Card`, `ui/FormField`,
  `ui/ImageUpload`, `QrCode`. `QuestionForm` rendert alle Typen in einer Komponente (statt 8 Dateien).
- **Standard-Zeitlimit** je neuer Frage aus `game.settings.default_time_limit` (20s).

## Phase 3 – Session-Backend & Realtime

- **`session_key` (UUID)** als unratbarer Channel-/Board-Schlüssel, getrennt von der PIN. Board-URL
  und Channel-Namen nutzen ihn, nie die PIN.
- **Kanäle (3 statt 4):** `session.{key}` (Presence: Schüler+Lehrer, trägt auch Steuer-Broadcasts),
  `session.{key}.teacher` (privat: Antworten/Fokus), `board.{key}` (**öffentlich**, unratbarer Key →
  Beamer ohne Login). Steuer- und Schülerdaten teilen sich so wenige Kanäle.
- **Gast-Autorisierung ohne Konten:** Presence-Channel erlaubt Gäste über `participant` in der
  Cookie-Session (Name/Emoji als Presence-Meta, keine echte ID). Lehrer-Channel nur Eigentümer.
- **Ein generisches Event `SessionEvent`** (statt vieler Klassen) mit `scope` (players/teacher/board)
  und `ShouldBroadcastNow` → synchron, **kein Queue-Worker/Redis** für Realtime nötig.
- **Schülersichere Aufbereitung** in `PublicQuestion`: `state` enthält nie `is_correct`/korrekte
  Werte; `reveal` liefert Lösungen separat erst bei Auflösung. Zwei-Phasen blendet Antworten in
  Phase „prompt" aus. Randomisierung als deterministischer Seed-Shuffle (reproduzierbar).
- **Server ist NICHT autoritativ für Antworten** – er broadcastet nur Steuerung/State; Antworten
  laufen (Phase 4/5) flüchtig über WebSocket zum Lehrer.
- **Timer** serverseitig als `question_deadline`; „set" und „extend" getrennt.
- **Beitrittsmethode-Default** beim Start = `qr` (fester Spiel-QR); `pin` erzeugt 6-stellige PIN.

## Phase 4 – Schüler-Flow & Proctoring

- **Teilnehmer-Identität nur in der Cookie-Session** (`participant`: session_key, token, label, kind).
  Kein DB-Eintrag. Der Client sichert dieselben Daten in `localStorage` für **Reconnect**.
- **Eindeutige Emojis** über `EmojiPoolService` mit **Cache** (nur Emoji-Strings pro session_key,
  TTL 4h) – Eindeutigkeit ohne Identitätsspeicherung. Bei erschöpftem Pool Wiederverwendung.
- **Moderation** greift schon beim Namen (Basisliste; DB-Erweiterung folgt Phase 7). Normalisierung
  entschärft Leetspeak/Trennzeichen.
- **Antworten & Fokus** gehen per HTTP-POST an den Server, der sie **nur** an den Lehrer-Channel
  broadcastet (`scope=teacher`) – **nie** persistiert. Bewusst HTTP statt Client-Whisper, damit der
  Server Teilnehmer-Session prüfen kann.
- **Warteseite** bei QR ohne laufende Session: Client pollt `/j/{token}/status` alle 3s und verbindet
  automatisch (Realtime-Kanal existiert erst mit Session).
- **Proctoring** (`useProctoring`): Fullscreen + Page Visibility + window blur; Best-Effort inkl. iOS.
  Ein Fokus-„weg"-Zustand wird entprellt gesendet, Rückkehr als `returned`.
- **Antworteingaben** in **einer** `AnswerInput`-Komponente (alle 8 Typen) + `DrawCanvas`
  (Pinselgröße/Farbe/Radierer). Antwortwert-Form je Typ dokumentiert im Code.
- **Play-Rendering** über den schülersicheren `state` (Presence-Channel-Events state/timer/reveal/
  ended); Zwei-Phasen blendet Antworten in Phase „prompt" aus.

## Phase 5 – Lehrer-Leitstand

- **Lehrer erhält vollständige Fragen inkl. Lösungen** im Control-Payload (er ist autorisiert),
  damit die **Wertung komplett lokal** im Browser läuft – der Server sieht Antworten nie.
- **`useTeacherSession`-Composable** hält den kompletten Live-Zustand (Roster via Presence,
  Antworten/Fokus via privatem Channel, State via `.state`) und spiegelt ihn in `localStorage`
  (`schulquizz.session.{key}`) → Reload-fest. `clear()` beim Beenden/Export.
- **Wertung** in `lib/scoring.ts` (rein): `grade()` (correct/wrong/manual/none) + `points()`
  mit **Schnelligkeits-Faktor** (base…base/2 über Restzeit) und **Nähe-Wertung** für Schätzfragen.
  Tie-Break = Summe der Antwortzeiten.
- **Start-Dialog** im Editor (Beitrittsmethode QR/PIN, Weiterschalten Lehrer/frei) → erzeugt Session
  und leitet zum Leitstand.
- **Leitstand-UI** wiederverwendet `Card`/`QrCode`; Live-Grid mit Fokus-Ampel (grau=offline,
  grün=ok, rot=weg), Antwort-Haken, Live-Punkten; Timer-Countdown; „Tafel öffnen" (2. Fenster).
- Steuerung per `router.post(preserveState)` – der neue Zustand kommt ohnehin via Broadcast zurück.

## Phase 6 – Wertung, Podium & Board

- **Board ist öffentlich** (`board.{key}`, unratbar) und erhält Aggregate über einen **Server-Relay**
  (`POST /sessions/{id}/board`): der Lehrer-Browser rechnet die anonyme Verteilung/das Podium und
  spiegelt sie an die Tafel. Server speichert nichts, relayed nur.
- **Anonyme Verteilung** (`lib/aggregate.ts`) je Typ: Balken (choice), Marker auf Skala (slider),
  Punkte auf Zahlenstrahl + Zielmarke (estimate), Wortwolke. Keine Namen.
- **Auflösung** sendet parallel an Schüler (`reveal`) und Tafel (Verteilung) – wählbarer Modus
  (richtige Antwort und/oder Verteilung) über `settings.reveal_mode`.
- **Podium** (`Podium.vue`, wiederverwendbar) 2-1-3-Treppchen + Rangliste; auf Tafel bei Spielende
  und im Leitstand.
- **Export** (`useResultExport`) als **CSV** (kein XLSX-Dep nötig; öffnet sauber in Excel/Calc,
  BOM gesetzt) mit Platz, Pseudonym, Punkten, Zeit und ✓/✗ je gewerteter Frage.
- **Spielende bleibt auf dem Leitstand** (Podium + Export sichtbar); „Zurück" räumt `localStorage`.

## Phase 7 – Moderation, i18n, Datenschutz/Landing

- **Basis-Sperrliste im Code** (`ModerationService::BASE_TERMS`) → immer aktiv, auch ohne Seed/DB
  (kein separater Seeder nötig; DB-Tabelle nur für Lehrer-Erweiterungen). Bewusste Entscheidung
  gegen Duplizierung.
- **Pro-Lehrer-Sperrliste** (`blocklist_terms`, `user_id`): greift bei Beitritt über die
  Session-Inhaber-ID; fremde Begriffe wirken nicht bei anderen Lehrkräften.
- **Landing-Seite** ersetzt Breeze-Welcome: Datenschutz in vier bildhaften Kacheln, Einstiege für
  Lehrer (Login) und Schüler (Beitreten), DE/EN.
- **Rate-Limiting**: Beitritt 20/min, Antwort/Fokus je 120/min.
- **`PRIVACY.md`** als Grundlage der Datenschutzerklärung; `.env.docker.example` erwähnt AVV.
- **i18n** DE/EN durchgängig für alle neuen Ansichten; Inhalte (Fragen) bleiben `translatable`
  vorbereitet ohne Editier-UI.
- **phpcs.xml** (PSR-12 statt PEAR) ergänzt, um IDE-Rauschen zu reduzieren (Laravel-Konvention).

## Phase 8 – Härtung & Abnahme

- **60 Feature-Tests** (251 Assertions) grün; decken Bibliothek, Editor/Fragetypen, Session-Steuerung,
  Beitritt/Play, Moderation, Board/Export und einen **durchgängigen Spielablauf** ab.
- **Produktions-Build** (`vue-tsc` Typecheck + Vite) fehlerfrei.
- **Boot-Smoke-Test**: `/`, `/login`, `/join` → 200; `/library` unauth → 302; `/j/unbekannt` → 404;
  59 Routen registriert; `reverb:start` verfügbar.
- **Verbleibende bewusste Grenzen** (siehe unten „Offene Punkte für dich").

---

## Nachträgliche Korrekturen (nach erstem Test)

- **Realtime-Bug (Schüler bekamen nichts):** Presence-Kanäle brauchen eine `/broadcasting/auth`-
  Autorisierung, die für **Gäste ohne Konto** unzuverlässig ist (CSRF/Session). Umgestellt auf
  **öffentliche Kanäle** `session.{key}` (Schüler, unratbarer Key, kein Auth) für Steuer-Events;
  Roster jetzt über `joined`/`left`-Broadcasts an den **privaten** Lehrer-Kanal. Antworten/Fokus
  weiterhin privat an den Lehrer. Deutlich robuster, gleiche Datensparsamkeit.
- **Optionaler Vollbildmodus:** neue Session-Einstellung `require_fullscreen` (Default **false**),
  umschaltbar im Start-Dialog. Fokus-/Fensterwechsel-Erkennung läuft **unabhängig davon immer**.
- **Dark/Light-Umschalter:** Tailwind `darkMode: 'class'`, `useTheme`-Composable + `ThemeToggle`,
  Init im Blade-Head (kein Flackern), Wahl in localStorage. In allen Layouts + Landing/Join.
- **Externe Schrift entfernt** (fonts.bunny.net) → erfüllt „keine externen Requests"; System-Font.
- **Demo-Seed:** `lehrer@schulquizz.test` / `passwort` + Beispiel-Quiz (`php artisan db:seed`).

## Erweiterungen ab dev-0.18

- **Escape-Room als eigener Spielmodus** (`games.mode = escape`, Struktur in `games.escape`). Rätsel
  sind normale Fragen; nur automatisch prüfbare Typen (choice, cloze, free_text, sort, estimate).
  Lösungen/Codes verlassen nie den Server: Prüfung zustandslos (`AnswerGrader`), Fortschritt
  meldet der Server an die Lehrkraft. Fortschritt der Schüler lokal + signierte Quittungen (HMAC
  mit `APP_KEY`) für das erneute Melden nach Leitstand-Reload.
- **Selbstgesteuert statt Lehrer-getaktet**; Zeitlimit über `question_deadline`, Pause friert die
  Restzeit ein (`paused_remaining_ms`).
- **Manuelle Bewertung** (Malen, Überstimmen bei eigener Antwort) im Leitstand, auch nach Spielende.
- **Robustheit (dev-0.22):** Zustand per HTTP nachladen nach Reconnect/Sichtbarkeitswechsel;
  Leitstand fordert beim Verbinden ein „resync“ an; Broadcasts schlagen nie bis zur Aktion durch
  (`SessionEvent::send`); Identität nur innerhalb derselben Session wiederverwenden.
- **Betrieb:** Scheduler + `schulquizz:cleanup`; supervisord mit unbegrenzten Neustarts;
  HEALTHCHECK; Image-Veröffentlichung nur nach grünen Tests (`ci.yml` → Job `publish`).
- **MCP (dev-0.23):** `laravel/mcp` + Passport (OAuth 2.1, dynamische Client-Registrierung).
  Bewusst nur Inhalte der eigenen Spiele; keine Live-Steuerung, keine Uploads. Eigene
  Zustimmungsseite ohne externe Fonts.

## Offene Punkte / Empfehlungen für dich

- **Realtime-E2E** ist nur backend-seitig getestet (Broadcasts via `Event::fake`). Für echte
  Presence-/WebSocket-Tests bräuchte es Laravel Dusk/Playwright + laufenden Reverb – bewusst
  ausgelassen. Manuell testbar mit `composer run dev` (startet auch Reverb).
- **Wertung ist client-seitig** (Lehrer-Browser). Das ist Absicht (Datensparsamkeit), heißt aber:
  fällt das Lehrergerät mitten im Spiel aus, ist der Live-Stand weg (localStorage überlebt Reload).
- **Basis-Sperrliste** ist absichtlich knapp; bitte fachlich/redaktionell erweitern.
- **Welcome.vue** (Breeze-Default) ist verwaist, aber harmlos – kann gelöscht werden.
- **Bilder per MCP** sind bewusst nicht möglich (Hintergrundbilder lädt die Lehrkraft im Editor hoch).
- **APP_KEY im Docker**: wird automatisch erzeugt und im Volume `storage/` gespeichert – Volume einrichten!
