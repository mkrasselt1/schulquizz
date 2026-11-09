<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Question;
use App\Models\User;
use App\Support\EscapeRoom;
use App\Support\GameImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exportiert/Importiert Spiele als portables Format (.quiz.json), inklusive Fragen,
 * Antworten und eingebetteter Bilder (Base64).
 *
 * Import erzeugt stets eine EIGENSTÄNDIGE Kopie beim Zielnutzer mit neuem join_token,
 * sodass Lehrkräfte Spiele sichern und untereinander teilen können.
 */
class GamePorter
{
    public const FORMAT = 'schulquizz.game';
    public const VERSION = 1;

    public function export(Game $game): array
    {
        $game->loadMissing(['questions.answerOptions']);

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'game' => [
                'title' => $game->getTranslations('title'),
                'description' => $game->getTranslations('description'),
                'base_locale' => $game->base_locale,
                'mode' => $game->mode ?? 'quiz',
                'settings' => $game->settings,
                'escape' => $game->isEscape() ? $this->exportEscape($game) : null,
                'questions' => $game->questions->map(fn (Question $q) => [
                    'type' => $q->type,
                    'position' => $q->position,
                    'prompt_mode' => $q->prompt_mode,
                    'prompt_text' => $q->getTranslations('prompt_text'),
                    'prompt_image' => $this->embedImage($q->prompt_image_path),
                    'time_limit' => $q->time_limit,
                    'points' => $q->points,
                    'scored' => $q->scored,
                    'reveal_after' => $q->reveal_after,
                    'config' => $q->config,
                    'options' => $q->answerOptions->map(fn ($o) => [
                        'position' => $o->position,
                        'mode' => $o->mode,
                        'text' => $o->getTranslations('text'),
                        'image' => $this->embedImage($o->image_path),
                        'is_correct' => $o->is_correct,
                    ])->values(),
                ])->values(),
            ],
        ];
    }

    public function import(array $data, User $user): Game
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('Unbekanntes Dateiformat.');
        }

        // In reine Arrays umwandeln (Export liefert Collections; Datei-Import liefert JSON).
        $data = json_decode(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true);
        $gameData = is_array($data['game'] ?? null) ? $data['game'] : [];

        return DB::transaction(function () use ($gameData, $user) {
            $game = new Game();
            $game->user_id = $user->id;
            $game->base_locale = $gameData['base_locale'] ?? 'de';
            $game->setTranslations('title', $this->titles($gameData['title'] ?? []));
            $description = $this->translations($gameData['description'] ?? []);
            if ($description) {
                $game->setTranslations('description', $description);
            }
            $mode = $gameData['mode'] ?? 'quiz';
            $game->mode = in_array($mode, Game::MODES, true) ? $mode : 'quiz';
            $game->settings = array_merge(
                Game::DEFAULT_SETTINGS,
                is_array($gameData['settings'] ?? null) ? $gameData['settings'] : [],
            );
            $game->status = 'draft';
            $game->save();

            $questionIds = [];
            $position = 0;
            foreach ((array) ($gameData['questions'] ?? []) as $qData) {
                // Unbrauchbare Einträge überspringen, Index trotzdem halten (Escape-Verweise).
                $questionIds[] = is_array($qData) ? $this->importQuestion($game, $qData, $position++) : null;
            }

            if ($game->isEscape() && is_array($gameData['escape'] ?? null)) {
                $game->escape = EscapeRoom::sanitize($this->importEscape($game, $gameData['escape'], $questionIds), $game);
                $game->save();
            }

            return $game;
        });
    }

    /**
     * Escape-Struktur portabel machen: Fragen über ihren Listen-Index statt DB-ID,
     * Bilder eingebettet.
     */
    private function exportEscape(Game $game): array
    {
        $escape = EscapeRoom::of($game);
        $indexById = $game->questions->pluck('id')->flip();

        foreach ($escape['rooms'] as &$room) {
            $room['image'] = $this->embedImage($room['image_path'] ?? null);
            unset($room['image_path']);
            foreach ($room['hotspots'] as &$h) {
                $h['image'] = $this->embedImage($h['image_path'] ?? null);
                unset($h['image_path']);
                $h['question_ref'] = isset($h['question_id']) ? ($indexById[$h['question_id']] ?? null) : null;
                unset($h['question_id']);
            }
            unset($h);
        }
        unset($room);

        return $escape;
    }

    private function importEscape(Game $game, array $escape, array $questionIds): array
    {
        foreach (($escape['rooms'] ?? []) as $ri => $room) {
            $escape['rooms'][$ri]['image_path'] = $this->storeImage($game, $room['image'] ?? null);
            unset($escape['rooms'][$ri]['image']);
            foreach (($room['hotspots'] ?? []) as $hi => $h) {
                $ref = $h['question_ref'] ?? null;
                $escape['rooms'][$ri]['hotspots'][$hi]['question_id'] = is_int($ref) ? ($questionIds[$ref] ?? null) : null;
                $escape['rooms'][$ri]['hotspots'][$hi]['image_path'] = $this->storeImage($game, $h['image'] ?? null);
                unset($escape['rooms'][$ri]['hotspots'][$hi]['image'], $escape['rooms'][$ri]['hotspots'][$hi]['question_ref']);
            }
        }

        return $escape;
    }

    private function importQuestion(Game $game, array $qData, int $position): ?int
    {
        $type = $qData['type'] ?? 'choice';
        if (! in_array($type, Question::TYPES, true)) {
            return null;
        }
        $timeLimit = is_numeric($qData['time_limit'] ?? null) ? (int) $qData['time_limit'] : null;

        $question = new Question([
            'position' => $position, // lückenlos neu nummerieren
            'type' => $type,
            'prompt_mode' => ($qData['prompt_mode'] ?? 'text') === 'image' ? 'image' : 'text',
            'prompt_image_path' => $this->storeImage($game, $qData['prompt_image'] ?? null),
            'time_limit' => $timeLimit ? max(3, min(3600, $timeLimit)) : null,
            'points' => max(0, min(100000, (int) ($qData['points'] ?? 1000))),
            'scored' => (bool) ($qData['scored'] ?? true),
            'reveal_after' => isset($qData['reveal_after']) ? (bool) $qData['reveal_after'] : null,
            'config' => is_array($qData['config'] ?? null) ? $qData['config'] : [],
        ]);
        $question->game_id = $game->id;
        $question->setTranslations('prompt_text', $this->translations($qData['prompt_text'] ?? []));
        $question->save();

        foreach (array_values((array) ($qData['options'] ?? [])) as $i => $oData) {
            if (! is_array($oData)) {
                continue;
            }
            $option = $question->answerOptions()->make([
                'position' => is_numeric($oData['position'] ?? null) ? (int) $oData['position'] : $i,
                'mode' => ($oData['mode'] ?? 'text') === 'image' ? 'image' : 'text',
                'image_path' => $this->storeImage($game, is_array($oData['image'] ?? null) ? $oData['image'] : null),
                'is_correct' => (bool) ($oData['is_correct'] ?? false),
            ]);
            $option->setTranslations('text', $this->translations($oData['text'] ?? []));
            $option->save();
        }

        return $question->id;
    }

    /**
     * Bild als data-URI (Base64) einbetten, sofern vorhanden.
     */
    private function embedImage(?string $path): ?array
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return [
            'mime' => $mime,
            'data' => base64_encode($contents),
        ];
    }

    /**
     * Eingebettetes Bild dekodieren und beim Zielspiel ablegen; gibt den neuen Pfad zurück.
     */
    private function storeImage(Game $game, mixed $image): ?string
    {
        if (! is_array($image) || empty($image['data']) || ! is_string($image['data'])) {
            return null;
        }

        $binary = base64_decode($image['data'], true);
        if ($binary === false) {
            return null;
        }

        // SVG nur bereinigt übernehmen (kein Script über präparierte Import-Dateien)
        if (($image['mime'] ?? null) === 'image/svg+xml') {
            return GameImage::storeSvg($game, $binary);
        }

        $ext = match ($image['mime'] ?? 'image/png') {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
        $path = "game-images/{$game->id}/".\Illuminate\Support\Str::random(20).'.'.$ext;
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /** Nur gültige Übersetzungen (Sprachkürzel => Text) übernehmen. */
    private function translations(mixed $value): array
    {
        if (is_string($value)) {
            return ['de' => $value];
        }
        $out = [];
        foreach ((array) $value as $loc => $text) {
            if (is_string($loc) && preg_match('/^[a-z]{2}$/', $loc) && is_scalar($text)) {
                $out[$loc] = (string) $text;
            }
        }

        return $out;
    }

    private function titles(mixed $title): array
    {
        if (is_string($title)) {
            return ['de' => $title];
        }
        return $this->translations($title) ?: ['de' => 'Importiertes Quiz'];
    }
}
