<?php

namespace App\Support;

use App\Models\Game;
use App\Models\Question;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Escape-Room-Struktur eines Spiels (games.escape).
 *
 * Aufbau:
 *  - rooms[]: Räume mit Hintergrundbild und klickbaren Hotspots (Koordinaten in % des Bildes)
 *  - Hotspot-Arten:
 *      clue   – zeigt einen Hinweis (Text/Bild)
 *      puzzle – öffnet ein Rätsel (= Frage des Spiels); gelöst → Belohnung (z. B. Code-Ziffer)
 *      lock   – Schloss mit Code; geöffnet → nächster Raum bzw. entkommen (letzter Raum)
 *  - requires[]: Hotspot erscheint erst, wenn diese Hotspots gelöst sind
 *
 * Lösungen (Codes, Belohnungen, Antworten) gehen NIE an Schüler-Browser – geprüft wird
 * serverseitig, zustandslos, ohne Speicherung.
 */
class EscapeRoom
{
    public const KINDS = ['clue', 'puzzle', 'lock'];

    public static function defaults(string $locale = 'de'): array
    {
        return [
            'intro' => [$locale => ''],
            'outro' => [$locale => ''],
            'time_limit' => 30,
            'show_hotspots' => true,
            'rooms' => [self::newRoom($locale, 1)],
        ];
    }

    public static function newRoom(string $locale, int $number): array
    {
        return [
            'id' => 'r_'.Str::lower(Str::random(8)),
            'title' => [$locale => ($locale === 'en' ? 'Room ' : 'Raum ').$number],
            'image_path' => null,
            'hotspots' => [],
        ];
    }

    /** Effektive Struktur (gespeichert oder Defaults). */
    public static function of(Game $game): array
    {
        return $game->escape ?: self::defaults($game->base_locale ?: 'de');
    }

    /**
     * Editor-Eingabe normalisieren: Typen erzwingen, Koordinaten begrenzen, fremde
     * Fragen und tote requires-Verweise entfernen.
     */
    public static function sanitize(array $input, Game $game): array
    {
        $validQuestionIds = $game->questions()
            ->whereIn('type', Game::ESCAPE_PUZZLE_TYPES)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $rooms = [];
        $allIds = [];
        foreach (array_slice((array) ($input['rooms'] ?? []), 0, 20) as $r) {
            $roomId = self::id($r['id'] ?? null, 'r_');
            $hotspots = [];
            foreach (array_slice((array) ($r['hotspots'] ?? []), 0, 60) as $h) {
                $kind = in_array($h['kind'] ?? null, self::KINDS, true) ? $h['kind'] : 'clue';
                $id = self::id($h['id'] ?? null, 'h_');
                $allIds[] = $id;
                $qid = isset($h['question_id']) ? (int) $h['question_id'] : null;
                $x = self::clamp($h['x'] ?? 0, 0, 100);
                $y = self::clamp($h['y'] ?? 0, 0, 100);
                $hotspots[] = [
                    'id' => $id,
                    'kind' => $kind,
                    'x' => $x,
                    'y' => $y,
                    'w' => self::clamp($h['w'] ?? 10, 1, 100 - $x),
                    'h' => self::clamp($h['h'] ?? 10, 1, 100 - $y),
                    'label' => self::translations($h['label'] ?? []),
                    'text' => self::translations($h['text'] ?? [], 5000),
                    'image_path' => self::path($h['image_path'] ?? null),
                    'hint' => self::translations($h['hint'] ?? [], 1000),
                    'question_id' => $kind === 'puzzle' && in_array($qid, $validQuestionIds, true) ? $qid : null,
                    'reward' => self::translations($h['reward'] ?? [], 1000),
                    'code' => $kind === 'lock' ? mb_substr(trim((string) ($h['code'] ?? '')), 0, 100) : '',
                    'requires' => array_values(array_filter((array) ($h['requires'] ?? []), 'is_string')),
                ];
            }
            $rooms[] = [
                'id' => $roomId,
                'title' => self::translations($r['title'] ?? []),
                'image_path' => self::path($r['image_path'] ?? null),
                'hotspots' => $hotspots,
            ];
        }

        // requires nur auf existierende, andere Hotspots.
        foreach ($rooms as &$room) {
            foreach ($room['hotspots'] as &$h) {
                $h['requires'] = array_values(array_unique(array_filter(
                    $h['requires'],
                    fn ($rid) => $rid !== $h['id'] && in_array($rid, $allIds, true),
                )));
            }
            unset($h);
        }
        unset($room);

        $limit = $input['time_limit'] ?? null;

        return [
            'intro' => self::translations($input['intro'] ?? [], 5000),
            'outro' => self::translations($input['outro'] ?? [], 5000),
            'time_limit' => $limit === null || $limit === '' ? null : (int) self::clamp($limit, 1, 600),
            'show_hotspots' => (bool) ($input['show_hotspots'] ?? true),
            'rooms' => $rooms ?: [self::newRoom($game->base_locale ?: 'de', 1)],
        ];
    }

    /**
     * Schülersichere Darstellung: ohne Codes, ohne Belohnungen, Rätsel ohne Lösungen.
     */
    public static function forPlayers(Game $game): array
    {
        $escape = self::of($game);
        $questions = $game->questions()->with('answerOptions')->get()->keyBy('id');

        $rooms = [];
        foreach ($escape['rooms'] as $room) {
            $hotspots = [];
            foreach ($room['hotspots'] as $h) {
                $public = [
                    'id' => $h['id'],
                    'kind' => $h['kind'],
                    'x' => $h['x'],
                    'y' => $h['y'],
                    'w' => $h['w'],
                    'h' => $h['h'],
                    'label' => $h['label'],
                    'hint' => $h['hint'],
                    'requires' => $h['requires'],
                ];
                if ($h['kind'] === 'clue') {
                    $public['text'] = $h['text'];
                    $public['image_url'] = self::url($h['image_path']);
                }
                if ($h['kind'] === 'puzzle') {
                    $q = $h['question_id'] ? $questions->get($h['question_id']) : null;
                    $public['question'] = $q instanceof Question
                        ? PublicQuestion::forPlayers($q, true, $h['id'])
                        : null;
                }
                if ($h['kind'] === 'lock') {
                    $public['text'] = $h['text'];
                    $public['code_length'] = mb_strlen(self::normalizeCode($h['code']));
                }
                $hotspots[] = $public;
            }
            $rooms[] = [
                'id' => $room['id'],
                'title' => $room['title'],
                'image_url' => self::url($room['image_path']),
                'hotspots' => $hotspots,
            ];
        }

        return [
            'intro' => $escape['intro'],
            'outro' => $escape['outro'],
            'time_limit' => $escape['time_limit'],
            'show_hotspots' => $escape['show_hotspots'],
            'rooms' => $rooms,
        ];
    }

    /**
     * @return array{room_index: int, hotspot: array}|null
     */
    public static function findHotspot(Game $game, string $hotspotId): ?array
    {
        foreach (self::of($game)['rooms'] as $ri => $room) {
            foreach ($room['hotspots'] as $h) {
                if ($h['id'] === $hotspotId) {
                    return ['room_index' => $ri, 'hotspot' => $h];
                }
            }
        }

        return null;
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $code) ?? '');
    }

    public static function codeMatches(string $expected, mixed $given): bool
    {
        if (! is_scalar($given)) {
            return false;
        }
        $expected = self::normalizeCode($expected);

        return $expected !== '' && hash_equals($expected, self::normalizeCode((string) $given));
    }

    /** Alle von der Struktur referenzierten Bildpfade (für Export). */
    public static function imagePaths(array $escape): array
    {
        $paths = [];
        foreach ($escape['rooms'] ?? [] as $room) {
            $paths[] = $room['image_path'] ?? null;
            foreach ($room['hotspots'] ?? [] as $h) {
                $paths[] = $h['image_path'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    private static function id(mixed $id, string $prefix): string
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)
            ? $id
            : $prefix.Str::lower(Str::random(8));
    }

    private static function clamp(mixed $value, float $min, float $max): float
    {
        $v = is_numeric($value) ? (float) $value : $min;

        return round(max($min, min($max, $v)), 2);
    }

    private static function translations(mixed $value, int $max = 200): array
    {
        $out = [];
        foreach ((array) $value as $loc => $text) {
            if (is_string($loc) && preg_match('/^[a-z]{2}$/', $loc) && is_scalar($text)) {
                $out[$loc] = mb_substr((string) $text, 0, $max);
            }
        }

        return $out;
    }

    /** Nur Pfade aus dem eigenen Upload-Ordner zulassen (kein Path-Traversal). */
    private static function path(mixed $path): ?string
    {
        return is_string($path) && preg_match('#^game-images/\d+/[A-Za-z0-9_.-]+$#', $path) ? $path : null;
    }

    private static function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
