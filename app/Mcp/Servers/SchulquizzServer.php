<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateGame;
use App\Mcp\Tools\CreatePuzzle;
use App\Mcp\Tools\DeletePuzzle;
use App\Mcp\Tools\GetGame;
use App\Mcp\Tools\ListGames;
use App\Mcp\Tools\SetEscapeImage;
use App\Mcp\Tools\SetEscapeRooms;
use App\Mcp\Tools\UpdatePuzzle;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Tafelquiz')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
Tafelquiz ist eine datenschutzfreundliche Quiz- und Escape-Room-App für Schulen. Über diese
Schnittstelle bearbeitest du NUR die eigenen Spiele der angemeldeten Lehrkraft: Rätsel/Fragen
anlegen und ändern sowie die Räume eines Escape-Rooms gestalten. Live-Spiele und Schülerdaten
sind nicht zugänglich.

Typischer Ablauf für einen Escape-Room:
1. create-game mit mode "escape" (oder list-games / get-game für ein bestehendes Spiel).
2. Für jedes Rätsel create-puzzle (Typen: choice, cloze, free_text, sort, estimate).
   Die zurückgegebene question_id merken.
3. set-escape-rooms: Räume mit Hotspots festlegen. Koordinaten x/y/w/h in Prozent des
   Raumbildes (0–100). Hotspot-Arten:
   - clue: zeigt "text" (Hinweis)
   - puzzle: öffnet das Rätsel "question_id"; nach dem Lösen sehen Schüler "reward"
     (z. B. eine Ziffer des Schloss-Codes)
   - lock: verlangt "code"; öffnet den nächsten Raum, im letzten Raum ist es der Ausgang
   "requires": Liste von Hotspot-IDs (puzzle/lock), die erst gelöst sein müssen.
   Belohnungen sollten zusammen den Code ergeben, damit das Rätsel lösbar ist.
4. set-escape-image: Hintergrundbild je Raum setzen – am besten selbst als SVG zeichnen
   (Querformat, z. B. viewBox="0 0 1600 900"), die Gegenstände dort platzieren, wo die
   Hotspots liegen (x/y/w/h in Prozent des Bildes). Mit hotspot_id bekommt ein Hinweis
   (clue) ein eigenes Bild. Alternativ PNG/JPEG/WebP/GIF als Base64.
5. Prüfe die "warnings" in der Antwort und behebe sie.
Texte werden in der Basissprache des Spiels gespeichert (base_locale).
TXT)]
class SchulquizzServer extends Server
{
    protected array $tools = [
        ListGames::class,
        GetGame::class,
        CreateGame::class,
        CreatePuzzle::class,
        UpdatePuzzle::class,
        DeletePuzzle::class,
        SetEscapeRooms::class,
        SetEscapeImage::class,
    ];
}
