<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GameSession extends Model
{
    protected $fillable = [
        'join_method',
        'status',
        'advance_mode',
        'current_question_id',
        'current_phase',
        'settings',
        'question_started_at',
        'question_deadline',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'question_started_at' => 'datetime',
            'question_deadline' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (GameSession $session): void {
            if (empty($session->session_key)) {
                $session->session_key = (string) Str::uuid();
            }
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'current_question_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['lobby', 'active', 'paused'], true);
    }

    /**
     * Eindeutige, aktive PIN generieren (6-stellig, ohne führende Null-Kollisionen).
     */
    public static function generatePin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::whereIn('status', ['lobby', 'active', 'paused'])->where('pin', $pin)->exists());

        return $pin;
    }

    /**
     * Aktive Session eines Spiels (max. eine gemäß Konzept).
     */
    public static function activeForGame(Game $game): ?self
    {
        return static::where('game_id', $game->id)
            ->whereIn('status', ['lobby', 'active', 'paused'])
            ->latest('id')
            ->first();
    }
}
