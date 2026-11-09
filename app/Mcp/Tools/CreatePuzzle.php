<?php

namespace App\Mcp\Tools;

use App\Mcp\PuzzleData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Legt ein Rätsel (eine Frage) in einem eigenen Spiel an. Typen: choice (Auswahl), cloze (Lückentext), free_text (eigene Antwort), sort (Reihenfolge), estimate (Zahl schätzen). Gibt die question_id zurück, die du in set-escape-rooms als puzzle-Hotspot verwendest.')]
class CreatePuzzle extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate([
            'game_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'prompt' => ['nullable', 'string', 'max:5000'],
            'options' => ['nullable', 'array', 'max:20'],
        ]);
        $game = PuzzleData::ownGame($request->user(), $request->get('game_id'));
        $input = array_intersect_key($request->all(), array_flip(['prompt', 'options', 'multiple_choice', 'accepted_answers', 'correct_value', 'tolerance', 'unit']));

        $question = PuzzleData::createQuestion($game, (string) $request->get('type'), $input);

        return Response::json(PuzzleData::exportQuestion($question));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(['choice', 'cloze', 'free_text', 'sort', 'estimate'])->required(),
            'prompt' => $schema->string()->description('Fragetext. Lückentext (cloze): Lücken mit [[Antwort]] markieren, Alternativen mit | trennen, z. B. "Die Hauptstadt ist [[Berlin|berlin]]."'),
            'options' => $schema->array()->items($schema->object([
                'text' => $schema->string()->required(),
                'correct' => $schema->boolean()->description('Nur bei choice: ist diese Antwort richtig?'),
            ]))->description('Nur choice/sort: Antwortmöglichkeiten. Bei sort ist die angegebene Reihenfolge die Lösung.'),
            'multiple_choice' => $schema->boolean()->description('Nur choice: mehrere richtige Antworten erlauben.'),
            'accepted_answers' => $schema->array()->items($schema->string())->description('Nur free_text: akzeptierte Antworten (kleine Tippfehler werden toleriert).'),
            'correct_value' => $schema->number()->description('Nur estimate: richtiger Zahlenwert.'),
            'tolerance' => $schema->number()->description('Nur estimate: erlaubte Abweichung (0 = exakt).'),
            'unit' => $schema->string()->description('Nur estimate: Einheit, z. B. "m".'),
        ];
    }
}
