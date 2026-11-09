# Änderungen

Versionen = Git-Tags = Docker-Image-Tags auf Docker Hub (`mkrasselt1/schulquizz:<version>`;
Image und Repository behalten den alten Namen).
`latest` zeigt immer auf die neueste Version.

## v1.0.0 – Tafelquiz
- **Neuer Name: Tafelquiz** ([tafelquiz.de](https://tafelquiz.de)), vorher Arbeitstitel „Schulquizz“.
  Oberfläche, Link-Vorschau, KI-Assistenten-Schnittstelle (MCP) und Doku heißen jetzt so;
  Docker-Image `mkrasselt1/schulquizz` bleibt (Updates laufen wie gewohnt).
- Vorschaubild für Link-Vorschauen (Messenger/Schul-Chat) und Tafel-Favicon.
- Erste stabile Version: enthält alles bis dev-0.30 (Live-Quiz, Escape-Rooms inkl. „jederzeit
  spielbar“ und Abschluss-Codes, MCP mit OAuth, abgesicherte Datenschutz-Zusagen).

## dev-0.30
- Browser-Tab und Link-Vorschau zeigen den App-Namen statt „Laravel“; Beschreibung für
  Vorschauen (Messenger/Schul-Chat) und eigenes Favicon. Name und Beschreibung kommen aus
  `APP_NAME` bzw. `APP_TAGLINE` (Umbenennen ohne Neubau).

## dev-0.29
- Wie dev-0.28, mit korrigiertem Dockerfile (dev-0.28 ließ sich nicht bauen und wurde nie als Image
  veröffentlicht).

## dev-0.28 – Versprechen technisch abgesichert (nur Git-Tag, kein Image)
- **Sitzungen nur noch im verschlüsselten Cookie** statt in der Datenbank – Schülernamen, IPs und
  User-Agents landen nicht mehr in der Tabelle `sessions`. (Alte DB-Sitzungen räumt der Aufräumlauf weg.
  Einmalige Folge des Updates: alle müssen sich neu anmelden bzw. neu beitreten.)
- **Keine Zugriffslogs mehr:** auch php-fpm (Image) und nginx in der Compose-Variante; Fehler-Logs
  überall 14 Tage, Container-Logs in Compose begrenzt.
- **Content-Security-Policy** + Sicherheits-Header für jede Antwort (erzwingt „keine externen Dienste“);
  eigener `/up`-Health-Check statt Laravels Seite mit CDN-Schrift.
- **Wort-Sperrliste auch für Text-Antworten** (Treffer werden live durch „***“ ersetzt).
- Browser-Speicher: Schülerdaten früherer Spiele werden beim Beitritt gelöscht, alles nach 2 Tagen
  ohne Spiel; Lehrer-Live-Daten > 2 Tage bei jedem App-Start.
- Compose: Reverb-Sende-API `/apps` und Port 8080 nicht mehr öffentlich, Secret wird erzeugt statt
  `change-me`; `docker-compose.hub.yml` nutzt jetzt das All-in-one-Image (keine doppelten Dienste).
- Standalone: Reverb lauscht nur auf 127.0.0.1. Weitergeleitete IPs nur von Proxys aus privaten Netzen
  (`TRUSTED_PROXIES`), Registrierung gedrosselt, abgelaufene OAuth-Tokens werden täglich gelöscht.
- PHP ≥ 8.4 (composer.json), ungenutzte Laravel-Willkommensseite entfernt.

## dev-0.27
- **Export/Import mit Bildern abgesichert:** Raum- und Hinweisbilder (auch SVG), Fragen- und
  Antwortbilder landen sicher in der `.quiz.json` und werden beim Import wiederhergestellt.
  Der Bildtyp richtet sich jetzt nach der Dateiendung statt nach der Inhaltserkennung (die meldete SVG je
  nach System als `text/xml` → Bild ging beim Import verloren). Rasterbilder werden beim Import
  am Inhalt geprüft.

## dev-0.26
- **Fix:** Bei „jederzeit spielbaren“ Escape-Rooms wurde jede Lösung mit „Pausiert“ abgelehnt,
  solange die Lehrkraft den Raum nicht zusätzlich gestartet hatte. Die eigene Uhr startet jetzt
  beim Öffnen der Spielseite (spätestens bei der ersten Eingabe).

## dev-0.25
- **Bilder per KI-Assistent (MCP):** neues Werkzeug `set-escape-image` setzt Raum-Hintergründe
  und Hinweis-Bilder – als SVG (der Assistent kann Szenen selbst zeichnen) oder PNG/JPEG/WebP/GIF.
- SVGs werden streng bereinigt (keine Skripte, keine externen Verweise); nginx liefert
  `/storage/*.svg` zusätzlich mit gesperrter Content-Security-Policy aus.
- Import übernimmt SVG-Bilder (bereinigt); Raumbearbeitung per MCP lässt „jederzeit spielbar“ unverändert.

## dev-0.24
- **Escape-Rooms „jederzeit spielbar“:** Schalter im Editor; Schüler spielen jederzeit über den
  festen QR-Code/Link, jede:r mit eigener Zeit ab dem eigenen Start. Bleibt offen, bis die
  Lehrkraft den Schalter ausschaltet oder das Spiel im Leitstand beendet.
- **Abschluss-Code** beim Entkommen (auch im Klassenmodus): individuell, einzigartig, an den
  Namen gebunden und fälschungssicher – mit fertiger Nachricht zum Kopieren/Teilen (z. B. Schul-Chat).
- Neue Seite **„Abschluss-Codes prüfen“** für Lehrkräfte (Nachricht einfügen → echt/unecht, Name,
  Zeit, Datum). Es wird nichts gespeichert.

## dev-0.23
- **KI-Assistenten (MCP-Schnittstelle)** unter `/mcp` mit Anmeldung per OAuth 2.1:
  Spiele auflisten/ansehen/anlegen, Rätsel anlegen/ändern/löschen, Escape-Räume festlegen.
- Eigene deutsche Zustimmungsseite (ohne externe Fonts); verbundene Assistenten im Profil
  sehen und Zugriff entziehen.

## dev-0.22 – Robustheit für den unbeaufsichtigten Betrieb
- Live-Spiel übersteht WLAN-Aussetzer, gesperrte Tablets und Neuladen (Schüler, Tafel, Leitstand).
- Antworten nur zur aktuellen, offenen Frage; Antwortzeit misst der Server; Pause hält die Zeit an.
- Geteilte Tablets: keine Übernahme fremder Identität/Punkte; keine doppelten Emojis.
- Editor zeigt Speicherfehler, warnt vor ungespeicherten Änderungen; Escape-Editor ohne Datenverlust.
- Import/Export robust; Uploads bis 10 MB, Importe bis 20 MB.
- Sicherheit: Reverb-API nicht mehr öffentlich, eigenes Reverb-Secret je Installation,
  Sicherheits-Header; optionaler Registrierungscode / Registrierung abschaltbar.
- Betrieb: Dienste werden nie aufgegeben, Gesundheitscheck, verständliche Startfehler,
  SQLite für viele gleichzeitige Zugriffe; Image wird nur nach grünen Tests veröffentlicht.

## dev-0.21
- Escape-Editor: Raumbild, Raumname und Intro/Outro gingen beim Speichern verloren – behoben.

## dev-0.20
- Upload-Limit 10 MB (Handyfotos), ganze Klasse kann hinter einer Schul-IP gleichzeitig beitreten.
- Automatisches Aufräumen (Scheduler), Logs im Container-Log, Countdowns nach Serveruhr.

## dev-0.19
- Bild-Links unabhängig von `APP_URL` (vorher zeigten sie ohne gesetzte `APP_URL` auf `localhost`).

## dev-0.18
- **Escape-Room-Modus** (Räume, Hotspots, Rätsel, Schlösser, Live-Fortschritt).
- Bewertung von Mal-Antworten (und eigenen Antworten) im Leitstand.
- Duplizieren kopiert auch Fragen und Bilder; Dark-Mode-Lesbarkeit verbessert.

## dev-0.17
- Deployment-Härtung, Lückentext, vollständige Übersetzung DE/EN.

## dev-0.1
- Erste Version: datenschutzkonforme Live-Quiz-App.
