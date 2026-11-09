<?php

namespace App\Mcp;

use App\Models\Game;
use App\Models\Question;
use App\Models\User;
use App\Support\EscapeRoom;
use App\Support\QuestionDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Übersetzt zwischen dem einfachen MCP-Format (Texte als Strings in der Spielsprache)
 * und der internen Struktur (Übersetzungs-Maps, Antwortoptionen, Escape-JSON).
 * Andere Sprachfassungen bleiben beim Bearbeiten erhalten.
 */
class PuzzleData
{
    /** Spiel der angemeldeten Lehrkraft – fremde Spiele sind "nicht gefunden". */
    public static function ownGame(?User $user, mixed $gameId): Game
    {
        $game = $user?->games()->find((int) $gameId);
        if (! $game) {
            throw ValidationException::withMessages(['game_id' => 'Spiel nicht gefunden (oder nicht deins).']);
        }

        return $game;
    }

    public static function ownQuestion(?User $user, mixed $questionId): Question
    {
        $question = Question::with(['game', 'answerOptions'])->find((int) $questionId);
        if (! $question || ! $user || $question->game->user_id !== $user->id) {
            throw ValidationException::withMessages(['question_id' => 'Rätsel nicht gefunden (oder nicht deins).']);
        }

        return $question;
    }

    public static function allowedTypes(Game $game): array
    {
        return $game->isEscape() ? Game::ESCAPE_PUZZLE_TYPES : Question::TYPES;
    }

    // ------------------------------------------------------------------ Fragen

    public static function createQuestion(Game $game, string $type, array $input): Question
    {
        if (! in_array($type, self::allowedTypes($game), true)) {
            throw ValidationException::withMessages([
                'type' => 'Erlaubte Typen: '.implode(', ', self::allowedTypes($game)),
            ]);
        }

        return DB::transaction(function () use ($game, $type, $input) {
            $position = (int) $game->questions()->max('position') + 1;
            $question = QuestionDefaults::create($game, $type, $position);
            // Platzhalter-Texte der Vorlage nicht stehen lassen
            if (! array_key_exists('prompt', $input)) {
                $input['prompt'] = '';
            }

            return self::applyQuestion($question->load('answerOptions'), $input);
        });
    }

    /**
     * Felder setzen (nur die übergebenen). Rückgabe: frisch geladene Frage.
     */
    public static function applyQuestion(Question $question, array $input): Question
    {
        $locale = $question->game->base_locale ?: 'de';
        $config = $question->config ?? [];

        return DB::transaction(function () use ($question, $input, $locale, $config) {
            if (array_key_exists('prompt', $input)) {
                $texts = $question->getTranslations('prompt_text');
                $texts[$locale] = mb_substr((string) $input['prompt'], 0, 5000);
                $question->setTranslations('prompt_text', $texts);
            }

            switch ($question->type) {
                case 'choice':
                    if (array_key_exists('multiple_choice', $input)) {
                        $config['multiple'] = (bool) $input['multiple_choice'];
                    }
                    break;
                case 'free_text':
                    if (array_key_exists('accepted_answers', $input)) {
                        $config['accepted'] = array_values(array_filter(array_map(
                            fn ($a) => mb_substr(trim((string) $a), 0, 200),
                            (array) $input['accepted_answers'],
                        ), fn ($a) => $a !== ''));
                    }
                    break;
                case 'estimate':
                    foreach (['correct_value' => 'correct', 'tolerance' => 'tolerance'] as $in => $key) {
                        if (array_key_exists($in, $input) && is_numeric($input[$in])) {
                            $config[$key] = $input[$in] + 0;
                        }
                    }
                    if (array_key_exists('unit', $input)) {
                        $config['unit'] = mb_substr((string) $input['unit'], 0, 30);
                    }
                    break;
            }
            $question->config = $config;
            $question->save();

            if (in_array($question->type, ['choice', 'sort'], true) && array_key_exists('options', $input)) {
                self::replaceOptions($question, (array) $input['options'], $locale);
            }

            return $question->fresh(['answerOptions', 'game']);
        });
    }

    /**
     * Antworten ersetzen. Bestehende Optionen werden der Reihe nach wiederverwendet,
     * damit andere Sprachfassungen erhalten bleiben.
     */
    private static function replaceOptions(Question $question, array $options, string $locale): void
    {
        $existing = $question->answerOptions()->orderBy('position')->get()->values();
        $keep = [];
        foreach (array_values($options) as $i => $opt) {
            $text = is_array($opt) ? ($opt['text'] ?? '') : $opt;
            $correct = is_array($opt) && (bool) ($opt['correct'] ?? false);
            $model = $existing->get($i) ?? $question->answerOptions()->make(['mode' => 'text']);
            $texts = $model->exists ? $model->getTranslations('text') : [];
            $texts[$locale] = mb_substr((string) $text, 0, 1000);
            $model->fill(['position' => $i, 'is_correct' => $question->type === 'choice' ? $correct : false]);
            $model->setTranslations('text', $texts);
            $model->save();
            $keep[] = $model->id;
        }
        $question->answerOptions()->whereNotIn('id', $keep ?: [0])->delete();
    }

    public static function deleteQuestion(Question $question): void
    {
        $game = $question->game;
        $question->delete();
        if ($game->isEscape() && $game->escape) {
            $game->escape = EscapeRoom::sanitize($game->escape, $game);
            $game->save();
        }
    }

    public static function exportQuestion(Question $q): array
    {
        $locale = $q->game->base_locale ?: 'de';
        $out = [
            'question_id' => $q->id,
            'type' => $q->type,
            'prompt' => self::tr($q->getTranslations('prompt_text'), $locale),
        ];
        $config = $q->config ?? [];
        if (in_array($q->type, ['choice', 'sort'], true)) {
            $out['options'] = $q->answerOptions->sortBy('position')->values()->map(fn ($o) => array_filter([
                'text' => self::tr($o->getTranslations('text'), $locale),
                'correct' => $q->type === 'choice' ? (bool) $o->is_correct : null,
            ], fn ($v) => $v !== null))->all();
        }
        if ($q->type === 'choice') {
            $out['multiple_choice'] = (bool) ($config['multiple'] ?? false);
        }
        if ($q->type === 'free_text') {
            $out['accepted_answers'] = $config['accepted'] ?? [];
        }
        if ($q->type === 'estimate') {
            $out['correct_value'] = $config['correct'] ?? 0;
            $out['tolerance'] = $config['tolerance'] ?? 0;
            $out['unit'] = $config['unit'] ?? '';
        }

        return $out;
    }

    // ------------------------------------------------------------------ Escape-Räume

    public static function exportEscape(Game $game): array
    {
        $locale = $game->base_locale ?: 'de';
        $e = EscapeRoom::of($game);

        return [
            'intro' => self::tr($e['intro'] ?? [], $locale),
            'outro' => self::tr($e['outro'] ?? [], $locale),
            'time_limit_minutes' => $e['time_limit'] ?? null,
            'show_hotspots' => (bool) ($e['show_hotspots'] ?? true),
            'rooms' => array_map(fn ($r) => [
                'id' => $r['id'],
                'title' => self::tr($r['title'] ?? [], $locale),
                'has_background_image' => ! empty($r['image_path']),
                'hotspots' => array_map(fn ($h) => array_filter([
                    'id' => $h['id'],
                    'kind' => $h['kind'],
                    'x' => $h['x'], 'y' => $h['y'], 'w' => $h['w'], 'h' => $h['h'],
                    'label' => self::tr($h['label'] ?? [], $locale),
                    'text' => self::tr($h['text'] ?? [], $locale) ?: null,
                    'hint' => self::tr($h['hint'] ?? [], $locale) ?: null,
                    'question_id' => $h['kind'] === 'puzzle' ? $h['question_id'] : null,
                    'reward' => $h['kind'] === 'puzzle' ? (self::tr($h['reward'] ?? [], $locale) ?: null) : null,
                    'code' => $h['kind'] === 'lock' ? $h['code'] : null,
                    'requires' => $h['requires'] ?: null,
                ], fn ($v) => $v !== null), $r['hotspots'] ?? []),
            ], $e['rooms'] ?? []),
            'warnings' => self::warnings($game),
        ];
    }

    /**
     * Neue Raumstruktur aus dem MCP-Format übernehmen. Bilder (nur im Editor hochladbar)
     * und andere Sprachfassungen bleiben für gleiche IDs erhalten.
     */
    public static function applyEscape(Game $game, array $input): Game
    {
        $locale = $game->base_locale ?: 'de';
        $old = EscapeRoom::of($game);
        $oldRooms = collect($old['rooms'] ?? [])->keyBy('id');
        $oldHotspots = collect($old['rooms'] ?? [])->flatMap(fn ($r) => $r['hotspots'] ?? [])->keyBy('id');

        $merge = fn (array $existing, mixed $value) => is_string($value)
            ? [...$existing, $locale => $value]
            : $existing;

        $rooms = [];
        foreach ((array) ($input['rooms'] ?? []) as $r) {
            if (! is_array($r)) {
                continue;
            }
            $prev = isset($r['id']) ? ($oldRooms[$r['id']] ?? []) : [];
            $hotspots = [];
            foreach ((array) ($r['hotspots'] ?? []) as $h) {
                if (! is_array($h)) {
                    continue;
                }
                $ph = isset($h['id']) ? ($oldHotspots[$h['id']] ?? []) : [];
                $hotspots[] = [
                    'id' => $h['id'] ?? null,
                    'kind' => $h['kind'] ?? ($ph['kind'] ?? 'clue'),
                    'x' => $h['x'] ?? ($ph['x'] ?? 10),
                    'y' => $h['y'] ?? ($ph['y'] ?? 10),
                    'w' => $h['w'] ?? ($ph['w'] ?? 15),
                    'h' => $h['h'] ?? ($ph['h'] ?? 15),
                    'label' => $merge($ph['label'] ?? [], $h['label'] ?? null),
                    'text' => $merge($ph['text'] ?? [], $h['text'] ?? null),
                    'hint' => $merge($ph['hint'] ?? [], $h['hint'] ?? null),
                    'reward' => $merge($ph['reward'] ?? [], $h['reward'] ?? null),
                    'image_path' => $ph['image_path'] ?? null,
                    'question_id' => $h['question_id'] ?? ($ph['question_id'] ?? null),
                    'code' => (string) ($h['code'] ?? ($ph['code'] ?? '')),
                    'requires' => $h['requires'] ?? ($ph['requires'] ?? []),
                ];
            }
            $rooms[] = [
                'id' => $r['id'] ?? null,
                'title' => $merge($prev['title'] ?? [], $r['title'] ?? null),
                'image_path' => $prev['image_path'] ?? null,
                'hotspots' => $hotspots,
            ];
        }

        $game->escape = EscapeRoom::sanitize([
            'intro' => $merge($old['intro'] ?? [], $input['intro'] ?? null),
            'outro' => $merge($old['outro'] ?? [], $input['outro'] ?? null),
            'time_limit' => array_key_exists('time_limit_minutes', $input) ? $input['time_limit_minutes'] : ($old['time_limit'] ?? 30),
            'show_hotspots' => $input['show_hotspots'] ?? ($old['show_hotspots'] ?? true),
            'rooms' => $rooms ?: ($old['rooms'] ?? []),
        ], $game);
        $game->save();

        return $game;
    }

    /** Hinweise auf Stellen, an denen Schüler nicht weiterkämen. */
    public static function warnings(Game $game): array
    {
        $locale = $game->base_locale ?: 'de';
        $qids = $game->questions()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $out = [];
        foreach (EscapeRoom::of($game)['rooms'] ?? [] as $i => $r) {
            $name = self::tr($r['title'] ?? [], $locale) ?: 'Raum '.($i + 1);
            if (! collect($r['hotspots'] ?? [])->contains('kind', 'lock')) {
                $out[] = "{$name}: kein Schloss (lock) – hier kommt niemand weiter.";
            }
            if (empty($r['image_path'])) {
                $out[] = "{$name}: noch kein Hintergrundbild (bitte im Editor hochladen).";
            }
            foreach ($r['hotspots'] ?? [] as $h) {
                $hn = self::tr($h['label'] ?? [], $locale) ?: $h['id'];
                if ($h['kind'] === 'puzzle' && (! $h['question_id'] || ! in_array((int) $h['question_id'], $qids, true))) {
                    $out[] = "{$name} / {$hn}: kein gültiges Rätsel (question_id) zugeordnet.";
                }
                if ($h['kind'] === 'lock' && trim((string) $h['code']) === '') {
                    $out[] = "{$name} / {$hn}: Schloss ohne Code.";
                }
            }
        }

        return $out;
    }

    private static function tr(array $translations, string $locale): string
    {
        foreach ([$translations[$locale] ?? null, ...array_values($translations)] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return '';
    }
}
