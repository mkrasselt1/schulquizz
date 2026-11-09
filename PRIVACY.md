# Datenschutz (Kurzfassung)

Schulquizz ist auf **Datensparsamkeit** ausgelegt. Diese Datei fasst die technischen Maßnahmen
zusammen; sie ersetzt keine rechtliche Datenschutzerklärung, liefert aber die Grundlage dafür.

## Welche Daten werden verarbeitet?

**Lehrkräfte (Konten):**
- Name, E-Mail, gehashtes Passwort (Login).
- Erstellte Quizze und Escape-Rooms (Fragen, Antworten, Räume, Bilder). Liegen in der Datenbank
  bzw. im Speicherordner des Betreibers.
- Falls die Lehrkraft einen KI-Assistenten verbindet (MCP): OAuth-Zugangsdaten (Name des
  Assistenten, Token-Kennungen, Ablaufdatum). Widerrufbar im Profil; Tokens laufen nach 30 Tagen
  (Erneuerung bis 180 Tage) ab.

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

**Escape-Room:** Der Server prüft Rätsel-Antworten und Codes, **ohne sie zu speichern**, und meldet
nur „gelöst/nicht gelöst“ live an die Lehrkraft. Den eigenen Fortschritt hält das Schülergerät
(`localStorage`) zusammen mit signierten Quittungen, damit er nach einem Neuladen des Leitstands
erneut gemeldet werden kann.

**Auf dem Schülergerät** (`localStorage`/Cookie) liegen nur: Teilnehmer-Pseudonym, die zuletzt
abgegebene Antwort der aktuellen Frage bzw. der Escape-Fortschritt – damit ein Neuladen nichts
kaputt macht. Die serverseitige Teilnehmer-Session ist ein Cookie ohne Datenbankeintrag zu Antworten.

## Wie lange wird was aufbewahrt?

Ein stündlicher Aufräumlauf auf dem Server:
- beendet Live-Spiele, die 8 Stunden ohne Aktivität waren,
- löscht beendete Spiel-Sitzungen (enthalten keine Schülerdaten) nach 30 Tagen,
- löscht nicht mehr verwendete Bilder und abgelaufene Cache-Einträge (z. B. Emoji-Vergabe).

Server-Logs (Fehler) werden 14 Tage aufbewahrt; Zugriffs-Logs des Webservers sind abgeschaltet.
Im Lehrer-Browser werden Live-Daten älterer Spiele nach 2 Tagen entfernt.

## Was wird NICHT gemacht?

- Keine externen Dienste, CDNs, Web-Fonts oder Analyse-/Tracking-Tools.
- Keine Werbung, kein Profiling.
- Keine Weitergabe an Dritte.
- Kein Redis/Cloud-Broadcasting – der WebSocket-Server (Laravel Reverb) läuft lokal.

**Ausnahme auf Wunsch der Lehrkraft – KI-Assistenten (MCP):** Verbindet eine Lehrkraft selbst
einen externen KI-Assistenten, erhält dieser Anbieter die Inhalte der **eigenen Spiele** dieser
Lehrkraft (Fragen, Lösungen, Räume), die der Assistent abruft oder anlegt. **Schülerdaten sind
über die Schnittstelle nicht erreichbar** (es gibt keine). Ob ein KI-Dienst genutzt werden darf,
regelt die Schule; der Zugriff lässt sich im Profil jederzeit entziehen.

## Technische Maßnahmen

- Transportverschlüsselung (TLS) im Betrieb; gehashte Passwörter (bcrypt).
- Signierte, flüchtige Teilnehmer-Tokens (Cookie-Session), keine dauerhaften Kennungen.
- Rate-Limiting auf Beitritt/Antworten (pro Gerät, damit Klassen hinter einer Schul-IP spielen können).
- Antworten werden nur für die gerade offene Frage angenommen; Escape-Quittungen sind fälschungssicher signiert.
- Sicherheits-Header, interne WebSocket-Schnittstelle nicht öffentlich erreichbar, Schlüssel je Installation.
- Optional: Registrierung neuer Lehrkraft-Konten nur mit Code oder ganz abgeschaltet.
- Wort-Sperrliste gegen unangemessene Namen/Antworten (Basisliste + pro Lehrkraft erweiterbar),
  als reiner Live-Filter ohne Speicherung geprüfter Eingaben.

## Betrieb / Verantwortlichkeit

Die Anwendung ist **self-hostbar** (Docker). Verantwortliche Stelle im Sinne der DSGVO ist der
Betreiber (Schule/Schulträger). Für den Auftragsverarbeitungsvertrag (AVV) mit einem etwaigen
Hoster gilt die jeweilige Betreiber-Konstellation.
