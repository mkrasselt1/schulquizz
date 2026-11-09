<?php

namespace App\Mcp\Tools;

use App\Mcp\PuzzleData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use App\Models\Game;

#[Description('Legt ein neues Spiel an: mode "escape" für einen Escape-Room, "quiz" für ein Live-Quiz. Gibt die game_id zurück.')]
class CreateGame extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'mode' => ['required', 'in:quiz,escape'],
            'description' => ['nullable', 'string', 'max:2000'],
            'language' => ['nullable', 'in:de,en'],
        ]);

        $game = new Game();
        $game->user_id = $request->user()->id;
        $game->mode = $data['mode'];
        $game->base_locale = $data['language'] ?? 'de';
        $game->title = $data['title'];
        $game->description = $data['description'] ?? null;
        $game->save();

        return Response::json([
            'game_id' => $game->id,
            'title' => $game->title,
            'mode' => $game->mode,
            'editor_url' => route('games.edit', $game),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'mode' => $schema->string()->enum(['escape', 'quiz'])->required(),
            'description' => $schema->string(),
            'language' => $schema->string()->enum(['de', 'en'])->description('Sprache der Texte (Standard: de).'),
        ];
    }
}
