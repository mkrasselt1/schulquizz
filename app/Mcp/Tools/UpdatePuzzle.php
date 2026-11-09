<?php

namespace App\Mcp\Tools;

use App\Mcp\PuzzleData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Ändert ein bestehendes Rätsel. Nur übergebene Felder werden geändert; "options" ersetzt die komplette Antwortliste.')]
class UpdatePuzzle extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate([
            'question_id' => ['required', 'integer'],
            'prompt' => ['nullable', 'string', 'max:5000'],
            'options' => ['nullable', 'array', 'max:20'],
        ]);
        $question = PuzzleData::ownQuestion($request->user(), $request->get('question_id'));
        $input = array_intersect_key($request->all(), array_flip(['prompt', 'options', 'multiple_choice', 'accepted_answers', 'correct_value', 'tolerance', 'unit']));

        return Response::json(PuzzleData::exportQuestion(PuzzleData::applyQuestion($question, $input)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question_id' => $schema->integer()->required(),
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
