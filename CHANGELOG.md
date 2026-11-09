# Änderungen

Versionen = Git-Tags = Docker-Image-Tags auf Docker Hub (`mkrasselt1/schulquizz:<version>`).
`latest` zeigt immer auf die neueste Version.

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
