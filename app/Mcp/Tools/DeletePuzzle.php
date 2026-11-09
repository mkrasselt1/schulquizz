<?php

namespace App\Mcp\Tools;

use App\Mcp\PuzzleData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Löscht ein Rätsel. Hotspots im Escape-Room, die es benutzen, verlieren dadurch ihr Rätsel (siehe warnings in get-game).')]
class DeletePuzzle extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['question_id' => ['required', 'integer']]);
        $question = PuzzleData::ownQuestion($request->user(), $request->get('question_id'));
        $game = $question->game;
        PuzzleData::deleteQuestion($question);

        return Response::json([
            'deleted' => true,
            'warnings' => $game->isEscape() ? PuzzleData::warnings($game->fresh()) : [],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['question_id' => $schema->integer()->required()];
    }
}
