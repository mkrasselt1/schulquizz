<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Question;
use App\Models\User;
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
                'settings' => $game->settings,
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

        $gameData = $data['game'] ?? [];

        return DB::transaction(function () use ($gameData, $user) {
            $game = new Game();
            $game->user_id = $user->id;
            $game->base_locale = $gameData['base_locale'] ?? 'de';
            $game->setTranslations('title', $this->titles($gameData['title'] ?? []));
            if (! empty($gameData['description'])) {
                $game->setTranslations('description', (array) $gameData['description']);
            }
            $game->settings = $gameData['settings'] ?? Game::DEFAULT_SETTINGS;
            $game->status = 'draft';
            $game->save();

            foreach (($gameData['questions'] ?? []) as $qData) {
                $this->importQuestion($game, $qData);
            }

            return $game;
        });
    }

    private function importQuestion(Game $game, array $qData): void
    {
        $question = new Question([
            'position' => $qData['position'] ?? 0,
            'type' => $qData['type'] ?? 'choice',
            'prompt_mode' => $qData['prompt_mode'] ?? 'text',
            'prompt_image_path' => $this->storeImage($game, $qData['prompt_image'] ?? null),
            'time_limit' => $qData['time_limit'] ?? null,
            'points' => $qData['points'] ?? 1000,
            'scored' => $qData['scored'] ?? true,
            'reveal_after' => $qData['reveal_after'] ?? null,
            'config' => $qData['config'] ?? [],
        ]);
        $question->game_id = $game->id;
        $question->setTranslations('prompt_text', (array) ($qData['prompt_text'] ?? []));
        $question->save();

        foreach (($qData['options'] ?? []) as $oData) {
            $option = $question->answerOptions()->make([
                'position' => $oData['position'] ?? 0,
                'mode' => $oData['mode'] ?? 'text',
                'image_path' => $this->storeImage($game, $oData['image'] ?? null),
                'is_correct' => $oData['is_correct'] ?? false,
            ]);
            $option->setTranslations('text', (array) ($oData['text'] ?? []));
            $option->save();
        }
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
    private function storeImage(Game $game, ?array $image): ?string
    {
        if (! $image || empty($image['data'])) {
            return null;
        }

        $binary = base64_decode($image['data'], true);
        if ($binary === false) {
            return null;
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

    private function titles(mixed $title): array
    {
        if (is_string($title)) {
            return ['de' => $title];
        }
        $title = (array) $title;

        return $title ?: ['de' => 'Importiertes Quiz'];
    }
}
