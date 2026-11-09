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
#[Description('Listet die eigenen Spiele (Quizze und Escape-Rooms) der angemeldeten Lehrkraft.')]
class ListGames extends Tool
{
    public function handle(Request $request): Response
    {
        $games = $request->user()->games()->withCount('questions')->latest('updated_at')->get()
            ->map(fn ($g) => [
                'game_id' => $g->id,
                'title' => $g->title,
                'mode' => $g->mode,
                'base_locale' => $g->base_locale,
                'puzzles' => $g->questions_count,
                'updated_at' => $g->updated_at?->toIso8601String(),
            ]);

        return Response::json(['games' => $games]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
