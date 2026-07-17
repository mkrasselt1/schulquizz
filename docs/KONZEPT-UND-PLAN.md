# Konzept & Implementierungsplan: Datenschutzkonforme Quiz-Web-App ("Schulquizz")

## Context

Es soll eine DSGVO-konforme Live-Quiz-Plattform (Kahoot-artig, aber datensparsam und
self-hostbar) neu gebaut werden. Lehrer erstellen Spiele im Browser, verwalten sie in
einer geräteübergreifend synchronen Bibliothek und starten sie live; Schüler treten ohne
Konto per PIN bei. Kernanforderungen sind Echtzeit-Kommunikation (WebSockets), ein
Vollbild-/Fokus-Überwachungsmechanismus für die Schülergeräte, eine getrennte
Präsentationsansicht (Tafel/Beamer) und ein reiches Set an Fragetypen.

Projektverzeichnis ist leer → Greenfield. Getroffene Entscheidungen:
- **Stack:** Laravel (PHP) + Laravel Reverb (WebSockets), **kein Redis**
- **Hosting:** primär self-hostable (Docker), so gebaut dass auch EU-Cloud-SaaS möglich ist
- **Lehrer-Login:** E-Mail + Passwort (eigene Konten, datensparsam)
- **Schüler-Zugang:** Beitritt per **PIN oder festem QR-Code** (nie beides gleichzeitig),
  kein Konto, eigener Name **oder** automatisch vergebenes Emoji, session-only
- **Schülerdaten:** werden **nie in der DB gespeichert**, nur live per WebSocket zum Lehrer
  (localStorage), Export des Ausgangs als Tabelle
- **Spiele teilbar:** Export/Import als Datei (Kopie) zum Sichern & Teilen zwischen Lehrern
- **Fester QR pro Spiel:** stabiler `join_token`, damit der QR-Code in Materialien einbindbar ist
- **Moderation:** mitgelieferte, pro Lehrer erweiterbare Wort-Sperrliste für Namen/Antworten
- **Landing-Page:** erklärt die Datenschutz-Maßnahmen idiotensicher
- **Sessions:** max. eine aktive Session pro Spiel/Lehrer gleichzeitig; Spiel/QR beliebig oft
  nacheinander wiederverwendbar
- **Reconnect:** Best-Effort-Wiederbeitritt inkl. Punkte über Geräte-Token (localStorage)
- **Wertung:** Punkte + Schnelligkeits-Bonus, Tie-Break über Geschwindigkeit; Auflösung nach jeder
  Frage optional; Siegertreppchen (Top 3 + Rangliste) am Ende
- **Freitext:** automatischer Ähnlichkeits-/Alternativen-Match; **Malen:** anonyme Live-Bewertung
  auf Lehrerschirm/Tafel
- **Vollbild/Proctoring:** Best-Effort-Signal (iOS ohne echtes Vollbild), keine Prüfungssperre
- **Weitere Fragetypen:** Schätzfrage (Zahl, Nähe-Wertung), Wortwolke-Umfrage (bis X Wörter)
- **Auflösung:** nach der Folie optional **anonyme Ergebnis-Verteilung** (Summe/Marker auf
  Zeitstrahl/Zahlenstrahl/Balken/Wortwolke) statt nur der Musterlösung
- **Mehrsprachigkeit:** Oberfläche in **Deutsch und Englisch**, umschaltbar (i18n); Frage-/Antwort-
  **Inhalte architektonisch übersetzbar vorbereitet** (locale-keyed), Editier-UI noch nicht enthalten

---

## Technische Architektur

| Schicht | Wahl | Begründung |
|---|---|---|
| Backend | Laravel 11, PHP 8.3 | gewählt; reifes Auth/ORM/Jobs |
| Realtime | Laravel Reverb + Laravel Echo (Client) | erstanbieter-WebSocket-Server, keine Drittdienste (Pusher-frei) |
| Frontend | Inertia.js + Vue 3 + TypeScript + Tailwind | SPA-Gefühl ohne separate API; interaktive Live-Views |
| i18n | Laravel-Lang + `vue-i18n` (DE + EN) | Oberfläche mehrsprachig, umschaltbar; weitere Sprachen leicht ergänzbar |
| DB | MariaDB/MySQL | läuft self-host **und** EU-Cloud problemlos |
| Cache/Queue | **Database-Driver** (kein Redis) | Reverb läuft als Single-Node ohne Redis; Queue/Cache über DB-Tabellen → eine Abhängigkeit weniger, einfacheres Self-Hosting |
| Deployment | Docker Compose (app, reverb, mariadb, nginx) | ein Befehl für Schulen; SaaS-fähig |

**Kein Redis:** Laravel Reverb betreibt seinen Presence-/Broadcast-Zustand im Single-Node
in-memory; `QUEUE_CONNECTION=database`, `CACHE_STORE=database`. (Horizontale Skalierung
später via optionalem Reverb-Scaling-Adapter, für Schul-Self-Host nicht nötig.)

Wichtig für Datenschutz: **keine externen CDNs/Fonts/Analytics** — alle Assets lokal
gebündelt (Vite). Nur technisch notwendige Session-Cookies.

---

## Datenmodell (Migrationen / Eloquent-Modelle)

Grundprinzip: **Nur Lehrer-Inhalte (Spiele/Fragen) liegen in der DB. Schülerdaten NIE.**

- **users** — Lehrer: `name`, `email`, `password`. (Laravel Breeze Standard)
- **games** — Bibliothekseintrag/Vorlage: `user_id`, `title`, `description`,
  `join_token` (**stabil, unique, pro Spiel** — kodiert den festen QR-Start-Link, damit der
  Lehrer den QR-Code dauerhaft in Materialien einbinden kann),
  `settings` (JSON: Defaults für Timer, Weiterschalt-Modus, Randomisierung, zwei-Phasen, Draw-Optionen),
  `base_locale`, `status` (draft/ready). → Sync zwischen Lehrergeräten passiert automatisch, weil
  serverseitig pro Konto gespeichert; jedes eingeloggte Gerät sieht dieselbe Liste.
- **questions** — `game_id`, `position`, `type` (enum, s.u.), `prompt_mode` (text/image),
  `prompt_text`, `prompt_image_path`, `time_limit` (nullable), `points`, `scored` (bool, Wertung
  an/aus), `reveal_after` (bool, Auflösung nach der Frage),
  `config` (JSON: typ-spezifisch, z.B. Lückentext-Slots, Sortier-Zielreihenfolge, Draw-Palette,
  Slider-Pole).
- **answer_options** — `question_id`, `position`, `mode` (text/image), `text`,
  `image_path`, `is_correct`. (für Multiple-Choice & Bild-Antworten & Sortier-Items)
- **Übersetzbare Inhalte (architektonisch vorbereitet, noch ohne UI):** Textfelder von `games`,
  `questions`, `answer_options` (`title`/`prompt_text`/`text` …) werden als **`translatable`**
  angelegt — d.h. intern locale-keyed gespeichert (JSON-Spalte `{ "de": "…", "en": "…" }` bzw.
  Spatie-Translatable-Muster), mit `base_locale` je Spiel. Ausgeliefert wird die passende Sprache;
  fehlt eine Übersetzung → Fallback auf `base_locale`. So sind Frage-/Antwort-Übersetzungen später
  ohne Migration nachrüstbar; ein Editier-UI dafür ist **bewusst noch nicht** Teil dieses Plans.
- **game_sessions** — **minimaler** Laufzeit-Anker, keine personenbez. Daten:
  `game_id`, `user_id`, `pin` (6-stellig, nur wenn PIN-Modus), `join_method` (**pin** | **qr** —
  Lehrer wählt beim Start genau eine, nicht beides), `status` (lobby/active/paused/ended),
  `advance_mode` (free/teacher), `current_question_id`, `settings` (JSON-**Snapshot**, beim Start
  überschreibbar), `question_deadline`, `started_at`, `ended_at`.
  Existiert nur, um Beitritt zu routen + aktuellen Fortschritt zu tragen; wird bei Spielende
  entwertet. **Höchstens eine aktive Session pro Spiel** (und pro Lehrer) gleichzeitig — der feste
  QR ist damit immer eindeutig auflösbar; dasselbe Spiel kann **nacheinander beliebig oft** neu
  gestartet werden (QR bleibt gleich).
- **blocklist_terms** — Wort-Sperrliste für Moderation: `user_id` (nullable = globale Basisliste),
  `term`, `match_type` (exact/substring). Basisliste per Seeder; pro Lehrer erweiterbar.

**Bewusst KEINE Tabellen** für Teilnehmer, Antworten, Fokus-Events (s. nächster Abschnitt).

---

## Schülerdaten nur flüchtig — nie in der DB

Das ist die zentrale Datenschutz-Entscheidung:

- Teilnahme, Namen/Emojis, Antworten und Fokus-Ereignisse werden **ausschließlich über
  WebSocket transportiert** und **nirgends serverseitig gespeichert** (keine Tabellen, keine Logs).
- **Quelle der Wahrheit = das Lehrer-Gerät.** Die `SessionControl`-Seite hält den kompletten
  Live-Zustand (Teilnehmerliste, eingehende Antworten, Fokus-Alerts, Punkte) reaktiv im Speicher
  und spiegelt ihn in **`localStorage`**, damit ein versehentlicher Reload das Spiel nicht verliert.
- **Wertung passiert auf dem Lehrer-Client** (er besitzt die Musterlösungen). Der Server routet
  Antworten nur weiter, kennt sie nicht persistent.
- Bei **Spielende oder nach Export** wird der `localStorage`-Eintrag gelöscht → nichts bleibt.
- **Reconnect mit Punkten (Best-Effort):** Das Schülergerät legt beim Beitritt einen zufälligen
  `participant_token` im eigenen `localStorage` ab. Fällt die Verbindung aus, meldet sich das Gerät
  mit demselben Token erneut an; der Lehrer-Client (der die Punkte hält) erkennt den Token, stellt
  Identität + bisherige Punkte wieder her, statt einen neuen Spieler anzulegen. Rein flüchtig, ohne
  Server-Speicherung.
- **Export des Ausgangs:** Der Lehrer exportiert das Ergebnis aus dem Browser als **Tabelle**
  (CSV/XLSX) bzw. druckbare Ergebnis-/Bestenlisten-Ansicht (PDF via Druckdialog). Erst dieser
  Export erzeugt eine dauerhafte Datei — bewusst und lokal beim Lehrer.

Konsequenz: Reverb transportiert, MariaDB speichert nur Lehrer-Inhalte. Schließt ein Datenleck
von Schülerdaten praktisch aus.

---

## Echtzeit-Kanäle (Reverb / Echo)

Kanäle sind an die **Session** gebunden (interner Session-Key, nicht zwingend die PIN):

- `presence-session.{key}` — **Presence-Channel**: Live-Liste aller verbundenen Schüler
  (Beitritt/Verlassen automatisch) → speist Lehrer-Grid und Lobby. Presence-Meta trägt nur
  das flüchtige Pseudonym (Name **oder** Emoji), keine ID.
- `private-session.{key}.teacher` — nur Lehrer: empfängt Antworten, Fokus-Alerts, Fortschritt.
- `private-session.{key}` — Schüler: empfängt Lehrer-Steuerung (Frage, Timer, start/stop/pause,
  Ergebnis/Bestenliste).
- `private-session.{key}.board` — Tafel/Beamer-Ansicht (read-only Spiegel).

Steuer-Aktionen (start/stop, weiterschalten, Timer) laufen als HTTP-Requests → serverseitige
Zustandsänderung an `game_sessions` → `broadcast(...)`. **Schüler-Antworten & Fokus-Events werden
per Client-Broadcast/Whisper direkt an den Lehrer-Channel gesendet** und vom Server *nicht*
persistiert — passend zum flüchtigen Datenkonzept.

---

## Ansichten (Routes / Inertia-Seiten)

**Öffentlich:**
0. `Landing` — Startseite, die die **Datenschutz-Maßnahmen idiotensicher** erklärt (s. eigener
   Abschnitt unten): kurze, bildhafte Punkte „Keine Schülerkonten", „Antworten werden nie
   gespeichert, nur live übertragen", „Keine Werbung/Tracker", „Selbst hostbar". Plus Einstieg
   für Lehrer (Login) und Schüler (Beitreten).

**Lehrer (auth):**
1. `Auth` — Login/Registrierung (Laravel Breeze, Inertia-Vue-Stack).
2. `Library` — Bibliothek: Spiele auflisten, duplizieren, löschen, neu — **plus Export
   (Download als `.quiz.json` inkl. eingebetteter Bilder) und Import (erzeugt eine eigenständige
   Kopie mit neuem `join_token`)**, sodass Lehrer Spiele untereinander teilen/sichern können.
3. `GameEditor` — Fragen-Builder mit allen Fragetypen, Bild-Upload, Drag-Sort der Fragen,
   Default-Parameter des Spiels. Zeigt den **festen QR-Code des Spiels** an (Druck/Download für
   Materialien).
4. `SessionControl` — Leit-Panel: Start-Dialog mit **Parameter-Override** (inkl. Wahl der
   Beitrittsmethode **PIN oder QR-Code**, nicht beides). Bei QR: der **feste, spielgebundene**
   QR-Code (aus `join_token`) wird angezeigt — identisch zu dem in den Materialien; bei PIN: die
   ephemere Session-PIN. Live-Schüler-Grid mit Fokus-Ampel, Frage weiterschalten, Timer live
   setzen/verlängern, start/stop/pause, Live-Antwortzähler, **Export-Button** für den Ausgang.
5. `PresenterBoard` — **separate Route** (`/board/{key}`), in zweitem Fenster auf dem Beamer
   geöffnet: zeigt aktuelle Frage / **optionale Auflösung nach der Frage** / Ergebnis-Verteilung /
   Zwischen-Rangliste und am Ende das **Siegertreppchen (Top 3 + Rangliste darunter)**, ohne
   Lösungen im falschen Moment. Synchron via `board`-Channel.

**Schüler (kein Konto):**
6. `Join` — je nach `join_method` **entweder** PIN eingeben **oder** über den festen QR-Link
   `/j/{join_token}` ankommen (nie beides). Feste QR-URL prüft serverseitig, ob gerade eine aktive
   Session zu diesem Spiel läuft: wenn ja → rein; wenn nein → freundliche „Noch kein Spiel
   gestartet"-Warteseite. Danach **entweder** eigenen Namen eingeben (gegen Sperrliste geprüft)
   **oder** „anonym" → bekommt **automatisch ein eindeutiges Emoji** zugewiesen (nicht selbst wählbar).
7. `Lobby` — Warten auf Start; fordert Vollbild an.
8. `Play` — Frage-/Antwort-Ansicht im Vollbild, typ-spezifische Eingabe, Warte-/Ergebnis-Screens.

---

## Fragetypen & Spielmechaniken (alle geforderten Punkte)

Umsetzung teils über `questions.type`, teils über `config`/`settings`-Flags:

- **1–N Fragen** → geordnete `questions` je Spiel.
- **Weiterschalten frei / durch Lehrer** → `session.advance_mode`; bei „frei" schaltet der
  Schüler selbst weiter, bei „Lehrer" nur auf Broadcast.
- **Zeitlimit pro Frage, verlängern / live setzen** → `question_deadline` serverseitig;
  Lehrer-Buttons „+Zeit" und „Zeit jetzt setzen" broadcasten neuen Deadline.
- **Antwort-Reihenfolge randomisieren** → Flag; Options-Shuffle **pro Teilnehmer** (Seed je
  participant), Wertung über Options-ID unabhängig von Anzeige-Reihenfolge.
- **Erst Frage, dann Antworten (zwei Seiten)** → Flag `two_phase`; Phase 1 nur Prompt,
  Phase 2 Antworten (per Broadcast/Countdown freigeschaltet).
- **Auflösung nach jeder Frage (optional)** → Flag `reveal_after` (Spiel-Default in `settings`,
  pro Frage überschreibbar); **nach Ablauf/Weiterschalten** broadcastet der Lehrer die Auflösung an
  Schüler & Board. Anzeigemodus in `config` wählbar:
  - **richtige Antwort** (Markierung korrekt/falsch, bei Sortieren die Zielreihenfolge, bei Lückentext
    die Musterlösung), und/oder
  - **anonyme Ergebnis-Verteilung**: Summe/Marker **aller** gegebenen Antworten anonym auf einer
    passenden Skala — Balkenverteilung (Choice), **Marker auf dem Zeitstrahl / der Slider-Achse**
    (Slider), Punkte auf dem **Zahlenstrahl** (Schätzfrage), Wortwolke (Wortwolke). Zeigt also die
    Klassen-Antworten statt nur der Musterlösung.
- **Bild/Text-Frage** → `prompt_mode`.
- **Vorgegebene Antworten als Bild/Text** → `answer_options.mode`.
- **Lückentext** → `type=cloze`; Prompt mit Platzhaltern `{{1}}`, Slots + akzeptierte Lösungen in `config`.
- **Eigene Antwort** → `type=free_text`; **automatischer Ähnlichkeits-/Alternativen-Match**: in
  `config` liegen mehrere akzeptierte Lösungen/Alternativen; Wertung normalisiert (lowercase, Trim,
  Umlaut-/Tippfehler-Toleranz via Ähnlichkeitsmaß, konfigurierbare Schwelle) → automatisch
  richtig/falsch. Lehrer kann Einzelfälle nachkorrigieren.
- **Antwort malen** → `type=draw`; HTML-Canvas mit **Pinselgröße (mehrere Stufen) und Farbwahl
  (Palette)** sowie Radierer/Undo/Clear; das Zeichenergebnis geht als PNG (Data-URL) **nur per
  WebSocket** an Lehrer/Board (nicht in DB). **Anonyme Live-Bewertung:** die Zeichnungen erscheinen
  auf Lehrerbildschirm **oder Tafel ohne Name/Emoji**, der Lehrer bewertet sie live fair (Punkte/
  richtig-falsch), Zuordnung intern. Farben/Standard-Pinselgröße pro Frage in `config` (Draw-Palette).
- **Objekte sortieren (Drag&Drop)** → `type=sort`; `answer_options` als Items, Zielreihenfolge in
  `config`; Client nutzt eine Sortable-Vue-Komponente, Wertung per Reihenfolgevergleich.
- **Umfrage / Slider (ohne Wertung)** → `type=poll_slider`; ein Schieberegler **zwischen zwei Polen**
  (Text **oder** Bild links/rechts, in `config`), keine richtige Antwort. Ergebnis wird als
  **Verteilung** (Mittelwert/Histogramm) auf Board/Lehrer gezeigt, **vergibt keine Punkte**
  (immer `scored=false`).
- **Schätzfrage (Zahleneingabe)** → `type=estimate`; Schüler geben eine **Zahl** ein, korrekter
  Wert in `config`. Gewertet nach **Nähe**: wer am dichtesten dran ist, bekommt die meisten Punkte
  (Punkte fallen mit Abstand, konfigurierbare Toleranz/Kurve); Gleichstand über Antwortzeit.
  Board zeigt die nächsten Schätzungen / den Zielwert bei Auflösung.
- **Wortwolke-Umfrage (ohne Wertung)** → `type=word_cloud`; Schüler dürfen **bis zu X Wörter**
  eingeben (X in `config`), jedes gegen die Sperrliste geprüft. Board/Lehrer zeigen eine
  **Wortwolke** (Häufigkeit = Größe), `scored=false`.

---

## Wertung, Punkte & Siegertreppchen

- **Wertung pro Frage an/aus.** Jede Frage hat ein Flag `scored` (ja/nein). Nicht gewertete Fragen
  (z.B. Umfrage/Slider, Aufwärmfragen) zählen nicht zum Punktestand und tauchen nicht im Ranking
  auf — nur ihre Verteilung/Antworten werden angezeigt.
- **Punkte, keine Prozente.** Gewertete Fragen haben `points` (Basispunkte). Nur korrekte Antworten
  bekommen Punkte.
- **Schnelligkeit zählt:** Wer schneller richtig antwortet, bekommt **mehr Punkte** — Punkte
  skalieren mit der Restzeit (z.B. `points` bis `points/2` linear über das Zeitlimit; bei Fragen
  ohne Zeitlimit über die relative Antwortreihenfolge). Damit ist Geschwindigkeit direkt im
  Punktestand abgebildet.
- **Gleichstand:** Bei gleicher Gesamtpunktzahl gewinnt, wer über das Spiel **im Schnitt schneller**
  geantwortet hat (Summe der Antwortzeiten als Tie-Break) — passt zu „schnellere Antwort zählt mehr".
- Berechnung läuft **im Lehrer-Browser** (Quelle der Wahrheit, s. flüchtiges Datenkonzept); die
  benötigten Antwortzeiten kommen mit jeder Antwort per WebSocket mit.
- **Abschluss = Siegertreppchen:** Nach dem Spiel zeigt Board (und Lehrer) ein **Podium für die
  besten 3** (Platz 1 mittig/erhöht, 2 links, 3 rechts) mit Name/Emoji und Punkten; **alle
  weiteren Plätze** als Rangliste darunter. Fließt auch in den Tabellen-Export ein.

---

## Vollbild-/Fokus-Überwachung (Schülergerät)

Composable `useProctoring()` auf der `Play`-Seite:
- **Fullscreen API** — `requestFullscreen()` bei Start; `fullscreenchange` erkennt Verlassen.
- **Page Visibility API** — `visibilitychange` (Tabwechsel/Minimieren).
- **Window blur/focus** — App verliert Vordergrund.
Jedes Ereignis → **Broadcast auf den `teacher`-Channel** (nicht persistiert) → Lehrer-Grid färbt
Schüler rot + Zeitstempel-Log **im Lehrer-Browser (localStorage)**. Rückkehr sendet `returned`.

**Best-Effort über alle Geräte:** Desktop/Android erhalten echtes Vollbild; **iOS-Safari
unterstützt die Fullscreen-API nicht** → dort kein erzwungenes Vollbild, aber Tab-Wechsel/Blur/
Visibility-Alerts funktionieren trotzdem. Durchgängig als **pädagogisches Signal** kommuniziert,
nicht als manipulationssichere Prüfungssperre (Doku + Hinweis im UI).

---

## Datenschutz / DSGVO (durchgängig)

- **Datensparsamkeit:** Schüler ohne Konto, nur flüchtiges Session-Pseudonym; Schülerdaten
  landen nie in der DB (s. Abschnitt „Schülerdaten nur flüchtig").
- **Kein Löschkonzept nötig für Schülerdaten**, weil nichts persistiert wird — der Lehrer-Browser
  räumt seinen `localStorage` bei Spielende/Export selbst auf.
- **Keine Drittanbieter:** Reverb statt Pusher-Cloud, lokale Fonts/Assets, keine Analytics, kein Redis.
- **Sicherheit:** TLS-only, gehashte Passwörter (bcrypt), signierte Teilnehmer-Tokens,
  Rate-Limiting auf Join/PIN, CSRF.
- **Transparenz / Landing:** Die **Startseite erklärt die Maßnahmen idiotensicher** — große,
  bildhafte Kacheln in einfacher Sprache: „🙈 Keine Konten für Schüler", „🗑️ Antworten werden nie
  gespeichert — sie fliegen nur live zum Lehrer und sind danach weg", „🚫 Keine Werbung, keine
  Tracker", „🏫 Läuft auf dem Schul-Server". Darunter kurze Klartext-Erläuterung + Link zur
  ausführlichen Datenschutzerklärung. Zusätzlich `PRIVACY.md` + AVV-Vorlage + Self-Host-Anleitung.

---

## Namens-Automatik & Wort-Sperrliste (Moderation)

- **Emojis werden automatisch vergeben:** Wählt ein Schüler keinen Namen (oder Modus „anonym"),
  bekommt er automatisch ein **eindeutiges, noch freies Emoji** zugewiesen (Server/Presence-Channel
  stellt Eindeutigkeit sicher, z.B. deterministisch aus einer Emoji-Pool-Liste, kollisionsfrei).
  Der Schüler wählt das Emoji **nicht** selbst.
- **Sperrliste für untaugliche Begriffe:** Selbst gewählte Namen **und** Freitext-/eigene-Antworten
  werden gegen eine Wort-Sperrliste geprüft (rassistische Wörter, NS-Begriffe u.ä.).
  - **Basisliste** wird mitgeliefert (in Repo/Seeder, nicht personenbezogen).
  - **Pro-Lehrer erweiterbar:** Tabelle **blocklist_terms** (`user_id` nullable = global/Basis,
    `term`, `match_type` exact/substring) + UI im Lehrerbereich zum Ergänzen/Entfernen eigener Begriffe.
  - Prüfung passiert **client- und serverseitig** (normalisiert: lowercase, Leetspeak/Trennzeichen
    entschärft); Treffer → Eingabe wird abgelehnt mit Hinweis „Bitte anderen Namen wählen".
    Da nichts persistiert wird, ist das ein reiner Live-Filter, kein Speicher-Log.

---

## Schrittweiser Implementierungsplan

Aufeinander aufbauende Phasen; jede Phase ist für sich lauffähig/testbar und endet mit einem
klaren „Fertig, wenn". So kannst du nach jeder Phase Zwischenstände sehen.

### Phase 0 — Projekt-Setup & Infrastruktur
- Laravel 11 + PHP 8.3 aufsetzen, Git-Repo initialisieren.
- Breeze mit Inertia/Vue3/TS-Stack installieren, Tailwind konfigurieren.
- Reverb installieren + Echo-Client einrichten; `.env`: `BROADCAST_CONNECTION=reverb`,
  `QUEUE_CONNECTION=database`, `CACHE_STORE=database` (kein Redis).
- `vue-i18n` + Laravel-Lang-Grundgerüst (de/en) einbinden, Sprachumschalter-Stub.
- Docker-Compose (app, reverb, mariadb, nginx) + `Dockerfile` + `.env.example` (self-host & EU-Cloud).
- **Fertig, wenn:** `docker compose up` startet App+Reverb+DB, Login-Seite lädt, i18n schaltet DE/EN.

### Phase 1 — Auth & Bibliothek (inkl. Export/Import)
- Migration/Seeder: `users` (Breeze). Auth-Flows nutzbar.
- Migration `games` inkl. `join_token`, `settings`, `base_locale`, `status`; `translatable`-Textfelder.
- Library-UI: Spiele auflisten/erstellen/duplizieren/löschen (geräteübergreifender Sync durch DB).
- `GameExport`/Import: Download `.quiz.json` (inkl. eingebetteter Bilder als Base64), Import erzeugt
  Kopie mit **neuem** `join_token`.
- **Fertig, wenn:** Lehrer legt Spiel an, sieht es auf zweitem Gerät; Export→Import bei anderem
  Konto ergibt eigenständige Kopie.

### Phase 2 — Editor & Fragetypen (Content)
- Migrationen `questions` (inkl. `type`, `scored`, `reveal_after`, `points`, `time_limit`, `config`)
  und `answer_options` (`mode`, `is_correct`, translatable Text).
- Editor-UI: Fragenliste mit Drag-Sort, Frage-Parameter, Bild-Upload (PNG/JPG/WebP, Größenlimit).
- Editor-Teilformulare **pro Typ**: Choice, Cloze, FreeText(+Alternativen), Draw(+Palette),
  Sort, PollSlider, Estimate(+Zielwert/Toleranz), WordCloud(+max Wörter).
- Festen QR-Code des Spiels anzeigen/drucken/downloaden.
- **Fertig, wenn:** ein Spiel mit je einer Frage jedes Typs vollständig editier- und speicherbar ist.

### Phase 3 — Session-Backend & Realtime-Fundament
- Migration `game_sessions` (minimal, keine Schülerdaten); Regel „max. 1 aktive Session pro Spiel/Lehrer".
- `JoinTokenService` + Beitritts-Routing: `/j/{join_token}` (fest) **oder** PIN → aktive Session
  finden / Warteseite.
- `routes/channels.php`: Presence-/Private-Channels autorisieren; Steuer-Events
  (QuestionShown, TimerUpdated, GameStateChanged, RevealAnswer …).
- SessionController: start/stop/pause, weiterschalten, Timer setzen/verlängern → Broadcast.
- **Fertig, wenn:** Lehrer startet/stoppt Session, ein Test-Client empfängt Steuer-Events live.

### Phase 4 — Schüler-Flow (Join → Lobby → Play)
- `EmojiPoolService` (eindeutige Auto-Emojis) + `participant_token` (localStorage) für Reconnect.
- Join-Seite: Name (Sperrliste) **oder** anonym→Auto-Emoji; Lobby mit Vollbild-Anforderung.
- `useProctoring` (Fullscreen/Visibility/Blur, Best-Effort inkl. iOS).
- Play-Seite mit **allen** Typ-Eingabekomponenten; Antworten + Antwortzeit per WebSocket an Lehrer
  (nicht persistiert).
- **Fertig, wenn:** zwei Schüler-Tabs treten bei (Name + Emoji), spielen alle Typen durch, Reconnect
  behält Identität.

### Phase 5 — Lehrer-Leitstand (Live-State)
- `useLiveSessionStore` (reaktiver Live-Zustand + `localStorage`-Spiegel, Aufräumen bei Ende/Export).
- SessionControl-UI: Start-Dialog mit Parameter-Override + Beitrittsmethode (PIN **oder** QR),
  Live-Schüler-Grid mit Fokus-Ampel, Live-Antwortzähler, Weiterschalten, Timer live.
- **Fertig, wenn:** Lehrer steuert ein komplettes Spiel; Grid zeigt Beitritte/Fokus-Alerts live;
  Reload verliert nichts.

### Phase 6 — Auflösung, Wertung, Podium & Board
- Client-Wertung je Typ (Punkte, Schnelligkeits-Bonus, Nähe bei Schätzfrage, Tie-Break über Zeit).
- Auflösung nach der Frage: richtige Antwort **und/oder anonyme Ergebnis-Verteilung**
  (Balken/Zeitstrahl/Zahlenstrahl/Wortwolke).
- Anonyme Live-Bewertung für Draw (Lehrer/Board).
- `PresenterBoard` (`/board/{key}`): Frage / Auflösung / Zwischen-Rangliste / **Siegertreppchen**.
- `useResultExport`: Ausgang als CSV/XLSX + druckbare Ansicht.
- **Fertig, wenn:** Spielende zeigt Podium (Top 3 + Rangliste) auf Board; Export erzeugt Tabelle.

### Phase 7 — Moderation, i18n-Feinschliff & Datenschutz
- Migration `blocklist_terms` + `BlocklistSeeder` (Basisliste) + Lehrer-UI zum Erweitern;
  `ModerationService` client- und serverseitig (Namen, Freitext, Wortwolke).
- Übersetzungen DE/EN vervollständigen; Sprachumschalter final; `translatable`-Fallback prüfen.
- Landing-/Datenschutzseite (idiotensichere Kacheln), `PRIVACY.md`, AVV-Vorlage, Self-Host-Doku.
- Rate-Limiting (Join/PIN), Sicherheits-Feinschliff.
- **Fertig, wenn:** gesperrte Begriffe werden abgelehnt, UI vollständig DE/EN, Landing erklärt
  Datenschutz, Doku vorhanden.

### Phase 8 — Härtung & Abnahme
- End-to-End-Durchlauf (s. Verifikation), `php artisan test` (Wertung, Token/PIN, Kanäle,
  Import/Export, Moderation), Netzwerk-Check „keine externen Requests", DB-Check „keine Schülerdaten".
- **Fertig, wenn:** alle Verifikationspunkte grün.

### Kritische neu anzulegende Dateien (repräsentativ)
- `docker-compose.yml`, `Dockerfile`, `.env.example`
- `database/migrations/*` (Datenmodell oben), `database/seeders/BlocklistSeeder.php`
- `app/Models/{Game,Question,AnswerOption,GameSession,BlocklistTerm}.php` (bewusst keine Schüler-Modelle)
- `app/Events/*` (QuestionShown, TimerUpdated, GameStateChanged, …) — Steuer-Events
- `app/Http/Controllers/{Library,GameExport,GameEditor,Session,Join,Board,Blocklist}Controller.php`
- `app/Services/{JoinTokenService,ModerationService,EmojiPoolService}.php`
- `routes/web.php`, `routes/channels.php`
- `resources/js/Pages/{Landing,Library,GameEditor,SessionControl,PresenterBoard,Join,Lobby,Play}.vue`
- `resources/js/Composables/{useEcho,useProctoring,useGameTimer,useLiveSessionStore,useResultExport}.ts`
- `resources/js/Components/QuestionTypes/*` (Choice, Cloze, FreeText, Draw[Pinsel/Farbe], Sort,
  PollSlider, Estimate, WordCloud …)
- `lang/{de,en}/*.php` + `resources/js/i18n/{de,en}.ts` (Übersetzungen), Sprachumschalter

---

## Verifikation (End-to-End)

- **Setup:** `docker compose up` → App, Reverb, DB laufen (kein Redis); Migrationen + Seeder.
- **Bibliothek-Sync:** in zwei Browsern denselben Lehrer einloggen → Spiel in einem anlegen →
  erscheint im anderen nach Reload/Broadcast.
- **Export/Teilen:** Spiel als `.quiz.json` exportieren → bei anderem Lehrer importieren → wird
  eigenständige Kopie mit **neuem** `join_token`.
- **Fester QR:** QR aus Editor merken → Spiel starten (QR-Modus) → derselbe QR führt in die
  laufende Session; ohne laufende Session zeigt er die Warteseite.
- **Voller Spielablauf:** Lehrer startet Session (Parameter-Override + Beitrittsmethode: einmal PIN,
  einmal QR) → zwei „Schüler"-Tabs treten bei (einmal Name, einmal anonym → **Auto-Emoji, eindeutig**)
  → erscheinen live im Grid + Lobby.
- **Fragetypen:** je eine Frage jedes Typs durchspielen; Randomisierung & Zwei-Phasen prüfen;
  Timer verlängern/live setzen; frei vs. lehrergesteuert weiterschalten; Draw mit Pinselgröße/Farbe
  (anonyme Live-Bewertung); Freitext-Ähnlichkeitsmatch; **Umfrage-Slider ohne Wertung**;
  **Schätzfrage** (näher dran = mehr Punkte); **Wortwolke** (bis X Wörter, Sperrliste greift);
  optionale Auflösung nach der Frage erscheint als richtige Antwort und/oder anonyme Verteilung
  auf Schüler & Board.
- **Mehrsprachigkeit:** Oberfläche auf Englisch umschalten → Lehrer- und Schüler-Ansichten
  erscheinen übersetzt; Umschalter wirkt sofort.
- **Wertung pro Frage:** gewertete Frage zählt zum Ranking, `scored=false`-Frage nicht.
- **Reconnect:** Schüler-Tab schließen/neu öffnen (gleicher Token) → gleiche Identität + Punkte.
- **Proctoring:** in Schüler-Tab Vollbild verlassen / Tab wechseln → Lehrer-Grid zeigt Alert +
  Log; Rückkehr setzt Status zurück.
- **Moderation:** gesperrten Begriff als Name/Freitext eingeben → wird abgelehnt; Lehrer ergänzt
  eigenen Begriff → wird ebenfalls abgelehnt.
- **Board:** `/board/{key}` im zweiten Fenster → Frage/Ergebnis/Bestenliste synchron.
- **Wertung, Podium & Export:** schnellere richtige Antwort erhält mehr Punkte; bei Punktgleichstand
  gewinnt der Schnellere; am Ende Siegertreppchen (Top 3 + Rangliste); Ausgang als Tabelle
  (CSV/XLSX) exportierbar.
- **Datenschutz (Kernnachweis):** Netzwerk-Tab = keine externen Requests; DB nach dem Spiel
  prüfen = **keine** Schüler-/Antwortdaten gespeichert; nach Spielende/Export ist der
  Lehrer-`localStorage` leer. `php artisan test` für Backend-Logik (join_token, PIN, Kanäle,
  Import/Export, Moderation).
