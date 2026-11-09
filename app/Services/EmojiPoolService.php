<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Vergibt automatisch eindeutige Emojis an anonyme Teilnehmer.
 *
 * Eindeutigkeit ohne Speicherung von Identität: pro Session wird im Cache nur die Menge der
 * bereits vergebenen Emojis gehalten (reine Emoji-Strings, keine personenbezogenen Daten).
 * Der Eintrag verfällt automatisch (TTL) – passend zum flüchtigen Datenkonzept.
 */
class EmojiPoolService
{
    private const TTL_MINUTES = 240;

    /** Großer Pool gut unterscheidbarer Emojis. */
    public const POOL = [
        '🦊', '🐼', '🦁', '🐯', '🐨', '🐵', '🦉', '🐧', '🐢', '🐙',
        '🦄', '🐝', '🦋', '🐬', '🐳', '🦖', '🦕', '🦩', '🦔', '🦦',
        '🍕', '🍔', '🍩', '🍦', '🍓', '🍑', '🍉', '🥑', '🌮', '🍿',
        '⚽', '🏀', '🎸', '🎨', '🚀', '🛸', '⭐', '🌈', '🔥', '❄️',
        '🎈', '🎁', '🎯', '🎲', '🧩', '🏆', '👑', '💎', '🍄', '🌵',
        '🐰', '🐸', '🐷', '🐮', '🐔', '🦆', '🦅', '🦇', '🐺', '🐗',
        '🌸', '🌻', '🌊', '🍀', '🌙', '☀️', '⚡', '🎃', '👾', '🤖',
    ];

    /**
     * Ein noch freies Emoji für die Session reservieren.
     */
    public function assign(string $sessionKey): string
    {
        return Cache::lock($this->cacheKey($sessionKey).':lock', 5)
            ->block(5, fn () => $this->assignUnlocked($sessionKey));
    }

    private function assignUnlocked(string $sessionKey): string
    {
        $taken = $this->taken($sessionKey);
        $available = array_values(array_diff(self::POOL, $taken));

        // Falls alle vergeben: Pool „wiederverwenden" (mehr Teilnehmer als Emojis).
        $emoji = ! empty($available)
            ? $available[random_int(0, count($available) - 1)]
            : self::POOL[random_int(0, count(self::POOL) - 1)];

        $taken[] = $emoji;
        Cache::put($this->cacheKey($sessionKey), array_values(array_unique($taken)), now()->addMinutes(self::TTL_MINUTES));

        return $emoji;
    }

    /**
     * Beim Verlassen freigeben (Best-Effort).
     */
    public function release(string $sessionKey, string $emoji): void
    {
        Cache::lock($this->cacheKey($sessionKey).':lock', 5)
            ->block(5, fn () => $this->releaseUnlocked($sessionKey, $emoji));
    }

    private function releaseUnlocked(string $sessionKey, string $emoji): void
    {
        $taken = array_values(array_diff($this->taken($sessionKey), [$emoji]));
        Cache::put($this->cacheKey($sessionKey), $taken, now()->addMinutes(self::TTL_MINUTES));
    }

    private function taken(string $sessionKey): array
    {
        return Cache::get($this->cacheKey($sessionKey), []);
    }

    private function cacheKey(string $sessionKey): string
    {
        return "session-emojis:{$sessionKey}";
    }
}
