<?php

namespace App\Support;

use App\Models\Question;

/**
 * Serverseitige Antwortprüfung (Gegenstück zu resources/js/lib/scoring.ts).
 *
 * Wird für den Escape-Room gebraucht: Dort dürfen die Lösungen NICHT an den Schüler-Browser
 * gehen, also prüft der Server – zustandslos, ohne die Antwort zu speichern.
 */
class AnswerGrader
{
    public static function isCorrect(Question $question, mixed $answer): bool
    {
        switch ($question->type) {
            case 'choice':
                $correct = $question->answerOptions->where('is_correct', true)->pluck('id')
                    ->map(fn ($id) => (int) $id)->sort()->values()->all();
                $given = self::intList($answer);
                sort($given);

                return $given !== [] && $correct === $given;

            case 'sort':
                $correct = $question->answerOptions->sortBy('position')->pluck('id')
                    ->map(fn ($id) => (int) $id)->values()->all();

                return $correct !== [] && $correct === self::intList($answer);

            case 'cloze':
                $given = is_array($answer) ? array_values($answer) : [];
                $threshold = (float) ($question->config['threshold'] ?? 0.85);
                // Jede Sprachfassung hat eigene Lücken – richtig, wenn eine davon vollständig passt.
                foreach ($question->getTranslations('prompt_text') as $raw) {
                    $gaps = PublicQuestion::clozeGaps((string) $raw);
                    if ($gaps === []) {
                        continue;
                    }
                    $all = true;
                    foreach ($gaps as $i => $gap) {
                        if (! self::matchesAny($gap['accepted'], (string) ($given[$i] ?? ''), $threshold)) {
                            $all = false;
                            break;
                        }
                    }
                    if ($all) {
                        return true;
                    }
                }

                return false;

            case 'free_text':
                if (! is_scalar($answer)) {
                    return false;
                }

                return self::matchesAny(
                    (array) ($question->config['accepted'] ?? []),
                    (string) $answer,
                    (float) ($question->config['threshold'] ?? 0.8),
                );

            case 'estimate':
                if (! is_numeric($answer)) {
                    return false;
                }
                $correct = (float) ($question->config['correct'] ?? 0);
                $tolerance = abs((float) ($question->config['tolerance'] ?? 0));

                return abs((float) $answer - $correct) <= $tolerance;

            default:
                return false;
        }
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }

    /** Levenshtein-Ähnlichkeit 0..1 (multibyte-sicher, wie im Frontend). */
    public static function similarity(string $a, string $b): float
    {
        $a = mb_str_split(self::normalize($a));
        $b = mb_str_split(self::normalize($b));
        $m = count($a);
        $n = count($b);
        if ($m === 0 && $n === 0) {
            return 1.0;
        }
        if ($m === 0 || $n === 0) {
            return 0.0;
        }

        $prev = range(0, $n);
        for ($i = 1; $i <= $m; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $n; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }

        return 1 - $prev[$n] / max($m, $n);
    }

    private static function matchesAny(array $accepted, string $given, float $threshold): bool
    {
        if (trim($given) === '') {
            return false;
        }
        foreach ($accepted as $acc) {
            $acc = (string) $acc;
            if (trim($acc) === '') {
                continue;
            }
            if (self::normalize($acc) === self::normalize($given) || self::similarity($acc, $given) >= $threshold) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_numeric')));
    }
}
