<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Game extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'title',
        'description',
        'base_locale',
        'mode',
        'settings',
        'escape',
        'status',
    ];

    /** Spielmodi: klassisches Live-Quiz oder selbstgesteuerter Escape-Room. */
    public const MODES = ['quiz', 'escape'];

    /** Fragetypen, die sich als Escape-Room-Rätsel eignen (automatisch prüfbar). */
    public const ESCAPE_PUZZLE_TYPES = ['choice', 'cloze', 'free_text', 'sort', 'estimate'];

    protected $attributes = [
        'mode' => 'quiz',
    ];

    protected array $translatable = ['title', 'description'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'escape' => 'array',
        ];
    }

    /**
     * Sinnvolle Spiel-Defaults, falls nichts gesetzt ist.
     */
    public const DEFAULT_SETTINGS = [
        'advance_mode' => 'teacher',   // teacher | free
        'default_time_limit' => 20,    // Sekunden, null = kein Limit
        'randomize_answers' => false,
        'two_phase' => false,          // erst Frage, dann Antworten
        'reveal_after' => true,        // Auflösung nach jeder Frage
        'reveal_mode' => 'correct',    // correct | distribution | both
        'require_fullscreen' => false, // Vollbild erzwingen (Fokus-Erkennung bleibt immer aktiv)
    ];

    protected static function booted(): void
    {
        // Hochgeladene Bilder des Spiels mitlöschen (sonst bleiben sie für immer liegen).
        static::deleted(function (Game $game): void {
            \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory("game-images/{$game->id}");
        });

        static::creating(function (Game $game): void {
            if (empty($game->join_token)) {
                $game->join_token = static::generateJoinToken();
            }
            if ($game->settings === null) {
                $game->settings = self::DEFAULT_SETTINGS;
            }
        });
    }

    public static function generateJoinToken(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (static::where('join_token', $token)->exists());

        return $token;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Effektiver Wert einer Einstellung mit Fallback auf die Defaults.
     */
    public function setting(string $key): mixed
    {
        return ($this->settings ?? self::DEFAULT_SETTINGS)[$key]
            ?? self::DEFAULT_SETTINGS[$key]
            ?? null;
    }

    public function isEscape(): bool
    {
        return $this->mode === 'escape';
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
