<?php

namespace App\Support;

use App\Models\Question;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bereitet Fragen für Schüler/Board auf – OHNE Lösungen – sowie die spätere Auflösung MIT Lösungen.
 * Zentralisiert, damit Broadcast, Play-Initialload und Board dieselbe Struktur nutzen.
 */
class PublicQuestion
{
    /**
     * Schülersichere Darstellung (keine is_correct/korrekten Werte).
     *
     * @param  bool  $withAnswers  Bei zweiphasigen Fragen erst in Phase "answers" true.
     * @param  string|null  $shuffleSeed  Optionaler Seed für pro-Teilnehmer-Mischung.
     */
    public static function forPlayers(Question $question, bool $withAnswers = true, ?string $shuffleSeed = null): array
    {
        $data = [
            'id' => $question->id,
            'type' => $question->type,
            'prompt_mode' => $question->prompt_mode,
            'prompt_text' => $question->getTranslations('prompt_text'),
            'prompt_image_url' => self::url($question->prompt_image_path),
            'points' => $question->points,
            'scored' => $question->isScored(),
            'time_limit' => $question->time_limit,
            'config' => self::publicConfig($question),
        ];

        if ($withAnswers && in_array($question->type, ['choice', 'sort'], true)) {
            $options = $question->answerOptions->map(fn ($o) => [
                'id' => $o->id,
                'mode' => $o->mode,
                'text' => $o->getTranslations('text'),
                'image_url' => self::url($o->image_path),
            ])->values()->all();

            // Antwort-Reihenfolge randomisieren (choice) bzw. für Sortieren immer mischen.
            $shouldShuffle = $question->type === 'sort'
                || ($question->type === 'choice' && ($question->config['randomize'] ?? false));

            if ($shouldShuffle) {
                $options = self::seededShuffle($options, $shuffleSeed ?? (string) $question->id);
            }

            $data['options'] = $options;
        }

        return $data;
    }

    /**
     * Auflösung MIT Lösungen (für Reveal auf Schüler & Board).
     */
    public static function reveal(Question $question): array
    {
        $payload = [
            'id' => $question->id,
            'type' => $question->type,
        ];

        switch ($question->type) {
            case 'choice':
                $payload['correct_option_ids'] = $question->answerOptions
                    ->where('is_correct', true)->pluck('id')->values()->all();
                break;
            case 'sort':
                // korrekte Reihenfolge = Original-Positionen
                $payload['correct_order'] = $question->answerOptions
                    ->sortBy('position')->pluck('id')->values()->all();
                break;
            case 'cloze':
            case 'free_text':
                $payload['accepted'] = $question->config['accepted'] ?? [];
                break;
            case 'estimate':
                $payload['correct'] = $question->config['correct'] ?? null;
                $payload['unit'] = $question->config['unit'] ?? '';
                break;
        }

        return $payload;
    }

    /**
     * Config ohne verräterische Felder (z.B. estimate.correct, accepted).
     */
    private static function publicConfig(Question $question): array
    {
        $config = $question->config ?? [];

        unset($config['accepted'], $config['correct'], $config['tolerance'], $config['threshold']);

        // Slider-Pole enthalten nur Anzeige-Text/Bild → unbedenklich.
        if ($question->type === 'estimate') {
            $config['unit'] = $question->config['unit'] ?? '';
        }

        return $config;
    }

    private static function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    private static function seededShuffle(array $items, string $seed): array
    {
        mt_srand(crc32($seed));
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        mt_srand();

        return $items;
    }
}
