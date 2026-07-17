# Datenschutz (Kurzfassung)

Schulquizz ist auf **Datensparsamkeit** ausgelegt. Diese Datei fasst die technischen Maßnahmen
zusammen; sie ersetzt keine rechtliche Datenschutzerklärung, liefert aber die Grundlage dafür.

## Welche Daten werden verarbeitet?

**Lehrkräfte (Konten):**
- Name, E-Mail, gehashtes Passwort (Login).
- Erstellte Quizze (Fragen, Antworten, Bilder). Liegen in der Datenbank des Betreibers.

**Schülerinnen und Schüler:**
- **Kein Konto.** Beitritt per PIN oder festem QR-Code.
- Ein selbst gewählter Name **oder** ein automatisch vergebenes Emoji (Pseudonym).
- Antworten und Fokus-Ereignisse (Tab-Wechsel etc.).

## Wo werden Schülerdaten gespeichert?

**Nirgends dauerhaft auf dem Server.** Teilnahme, Pseudonyme, Antworten und Fokus-Ereignisse
werden **ausschließlich live über WebSocket** an das Gerät der Lehrkraft übertragen und existieren
nur im Arbeitsspeicher/`localStorage` des Lehrer-Browsers, bis das Spiel endet oder das Ergebnis
exportiert wurde. Es gibt bewusst **keine** Datenbanktabellen für Teilnehmer, Antworten oder
Fokus-Ereignisse.

Erst ein **manueller Export** durch die Lehrkraft (CSV) erzeugt eine dauerhafte Datei – lokal auf
dem Lehrergerät.

## Was wird NICHT gemacht?

- Keine externen Dienste, CDNs, Web-Fonts oder Analyse-/Tracking-Tools.
- Keine Werbung, kein Profiling.
- Keine Weitergabe an Dritte.
- Kein Redis/Cloud-Broadcasting – der WebSocket-Server (Laravel Reverb) läuft lokal.

## Technische Maßnahmen

- Transportverschlüsselung (TLS) im Betrieb; gehashte Passwörter (bcrypt).
- Signierte, flüchtige Teilnehmer-Tokens (Cookie-Session), keine dauerhaften Kennungen.
- Rate-Limiting auf Beitritt/Antworten.
- Wort-Sperrliste gegen unangemessene Namen/Antworten (Basisliste + pro Lehrkraft erweiterbar),
  als reiner Live-Filter ohne Speicherung geprüfter Eingaben.

## Betrieb / Verantwortlichkeit

Die Anwendung ist **self-hostbar** (Docker). Verantwortliche Stelle im Sinne der DSGVO ist der
Betreiber (Schule/Schulträger). Für den Auftragsverarbeitungsvertrag (AVV) mit einem etwaigen
Hoster gilt die jeweilige Betreiber-Konstellation.
