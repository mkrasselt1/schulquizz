<?php

namespace App\Mcp\Tools;

use App\Mcp\PuzzleData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Zeigt ein eigenes Spiel vollständig: alle Rätsel/Fragen inkl. Lösungen und – bei Escape-Rooms – Räume, Hotspots und Warnungen.')]
class GetGame extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['game_id' => ['required', 'integer']]);
        $game = PuzzleData::ownGame($request->user(), $request->get('game_id'));
        $game->load('questions.answerOptions');

        return Response::json([
            'game_id' => $game->id,
            'title' => $game->title,
            'mode' => $game->mode,
            'base_locale' => $game->base_locale,
            'allowed_puzzle_types' => PuzzleData::allowedTypes($game),
            'puzzles' => $game->questions->map(fn ($q) => PuzzleData::exportQuestion($q->setRelation('game', $game)))->all(),
            'escape' => $game->isEscape() ? PuzzleData::exportEscape($game) : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['game_id' => $schema->integer()->required()];
    }
}
