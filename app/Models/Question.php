<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory;
    use HasTranslations;

    /** Alle unterstützten Fragetypen. */
    public const TYPES = [
        'choice',       // Auswahl (Einzel/Mehrfach über config.multiple), Antworten Text/Bild
        'cloze',        // Lückentext
        'free_text',    // eigene Antwort mit Ähnlichkeits-/Alternativen-Match
        'draw',         // Antwort malen (manuelle, anonyme Bewertung)
        'sort',         // Objekte sortieren (Drag & Drop)
        'poll_slider',  // Umfrage-Slider zwischen zwei Polen (ohne Wertung)
        'estimate',     // Schätzfrage (Zahl, Nähe-Wertung)
        'word_cloud',   // Wortwolke (ohne Wertung)
    ];

    /** Typen, die grundsätzlich keine Punkte vergeben. */
    public const UNSCORED_TYPES = ['poll_slider', 'word_cloud'];

    protected $fillable = [
        'position',
        'type',
        'prompt_mode',
        'prompt_text',
        'prompt_image_path',
        'time_limit',
        'points',
        'scored',
        'reveal_after',
        'config',
    ];

    protected array $translatable = ['prompt_text'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'scored' => 'boolean',
            'reveal_after' => 'boolean',
            'points' => 'integer',
            'time_limit' => 'integer',
            'position' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function answerOptions(): HasMany
    {
        return $this->hasMany(AnswerOption::class)->orderBy('position');
    }

    public function isScored(): bool
    {
        return $this->scored && ! in_array($this->type, self::UNSCORED_TYPES, true);
    }
}
