<?php

namespace App\Support;

use App\Models\Game;
use App\Models\Question;

/**
 * Erzeugt neue Fragen mit sinnvollen Standardwerten je Typ.
 * Zentralisiert, damit Editor, Import und Tests dieselben Defaults nutzen.
 */
class QuestionDefaults
{
    public static function create(Game $game, string $type, int $position): Question
    {
        $question = new Question([
            'position' => $position,
            'type' => $type,
            'prompt_mode' => 'text',
            'time_limit' => $game->setting('default_time_limit'),
            'points' => in_array($type, Question::UNSCORED_TYPES, true) ? 0 : 1000,
            'scored' => ! in_array($type, Question::UNSCORED_TYPES, true),
            'reveal_after' => null, // erbt Spiel-Default
            'config' => self::configFor($type),
        ]);
        $question->game_id = $game->id;
        // Lückentext bekommt einen Beispielsatz mit einer Lücke, damit sofort klar ist,
        // wie Lücken markiert werden.
        $prompt = $type === 'cloze'
            ? 'Die Hauptstadt von Deutschland ist [[Berlin]].'
            : '';
        $question->setTranslations('prompt_text', ['de' => $prompt]);
        $question->save();

        foreach (self::defaultOptions($type) as $i => $opt) {
            $option = $question->answerOptions()->make([
                'position' => $i,
                'mode' => 'text',
                'is_correct' => $opt['is_correct'] ?? false,
            ]);
            $option->setTranslations('text', ['de' => $opt['text']]);
            $option->save();
        }

        return $question;
    }

    public static function configFor(string $type): array
    {
        return match ($type) {
            'choice' => ['multiple' => false, 'randomize' => null],
            'cloze' => ['threshold' => 0.85],
            'free_text' => ['accepted' => [''], 'threshold' => 0.8],
            'draw' => [
                'palette' => ['#000000', '#e11d48', '#2563eb', '#16a34a', '#f59e0b', '#ffffff'],
                'brush_sizes' => [2, 6, 14, 28],
                'default_brush' => 6,
            ],
            'sort' => [], // korrekte Reihenfolge = Position der Items
            'poll_slider' => [
                'left' => ['mode' => 'text', 'text' => ['de' => 'Stimme nicht zu']],
                'right' => ['mode' => 'text', 'text' => ['de' => 'Stimme zu']],
                'live_results' => false,
            ],
            'estimate' => ['correct' => 0, 'tolerance' => 0, 'unit' => ''],
            'word_cloud' => ['max_words' => 3],
            default => [],
        };
    }

    /**
     * Standard-Antwortoptionen (nur für Typen mit answer_options).
     */
    public static function defaultOptions(string $type): array
    {
        return match ($type) {
            'choice' => [
                ['text' => 'Antwort A', 'is_correct' => true],
                ['text' => 'Antwort B', 'is_correct' => false],
                ['text' => 'Antwort C', 'is_correct' => false],
                ['text' => 'Antwort D', 'is_correct' => false],
            ],
            'sort' => [
                ['text' => 'Erstes'],
                ['text' => 'Zweites'],
                ['text' => 'Drittes'],
            ],
            default => [],
        };
    }
}
