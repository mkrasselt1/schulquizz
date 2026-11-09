<?php

namespace App\Mcp\Tools;

use App\Mcp\PuzzleData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Setzt die Räume eines eigenen Escape-Rooms (ersetzt die komplette Raumliste). Gleiche Raum-/Hotspot-IDs behalten ihre hochgeladenen Bilder. Antwort enthält den gespeicherten Stand und Warnungen (z. B. Raum ohne Schloss).')]
class SetEscapeRooms extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate([
            'game_id' => ['required', 'integer'],
            'rooms' => ['required', 'array', 'min:1', 'max:20'],
            'rooms.*.hotspots' => ['nullable', 'array', 'max:60'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);
        $game = PuzzleData::ownGame($request->user(), $request->get('game_id'));
        if (! $game->isEscape()) {
            throw ValidationException::withMessages(['game_id' => 'Dieses Spiel ist kein Escape-Room (mode "escape").']);
        }

        $game = PuzzleData::applyEscape($game, $request->all());

        return Response::json(PuzzleData::exportEscape($game));
    }

    public function schema(JsonSchema $schema): array
    {
        $hotspot = $schema->object([
            'id' => $schema->string()->description('Stabile ID, z. B. "h_tresor" (Buchstaben, Ziffern, _ und -).'),
            'kind' => $schema->string()->enum(['clue', 'puzzle', 'lock'])->required(),
            'x' => $schema->number()->description('Linke Kante in % der Bildbreite (0–100).'),
            'y' => $schema->number()->description('Obere Kante in % der Bildhöhe (0–100).'),
            'w' => $schema->number()->description('Breite in %.'),
            'h' => $schema->number()->description('Höhe in %.'),
            'label' => $schema->string()->description('Bezeichnung, z. B. "Schreibtisch".'),
            'text' => $schema->string()->description('clue: Hinweistext · lock: Aufforderung'),
            'hint' => $schema->string()->description('Optionaler Tipp auf Knopfdruck (puzzle/lock).'),
            'question_id' => $schema->integer()->description('puzzle: ID des Rätsels aus create-puzzle.'),
            'reward' => $schema->string()->description('puzzle: was Schüler nach dem Lösen sehen.'),
            'code' => $schema->string()->description('lock: Code (Groß-/Kleinschreibung und Leerzeichen egal).'),
            'requires' => $schema->array()->items($schema->string())->description('Hotspot-IDs (puzzle/lock), die vorher gelöst sein müssen.'),
        ]);

        return [
            'game_id' => $schema->integer()->required(),
            'intro' => $schema->string()->description('Geschichte zum Start.'),
            'outro' => $schema->string()->description('Text nach dem Entkommen.'),
            'time_limit_minutes' => $schema->integer()->description('Zeitlimit in Minuten (leer = keins).'),
            'show_hotspots' => $schema->boolean()->description('Klickbare Stellen für Schüler markieren.'),
            'rooms' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Stabile ID, z. B. "r_labor".'),
                'title' => $schema->string(),
                'hotspots' => $schema->array()->items($hotspot),
            ]))->required(),
        ];
    }
}
