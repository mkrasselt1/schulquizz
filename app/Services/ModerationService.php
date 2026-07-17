<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prüft Namen/Freitext gegen eine Wort-Sperrliste (Basisliste + pro-Lehrer erweiterbar).
 * Rein flüchtig – keine Speicherung geprüfter Eingaben.
 *
 * Die pro-Lehrer-Erweiterung (Tabelle `blocklist_terms`) kommt in Phase 7; diese Klasse
 * funktioniert bereits mit der eingebauten Basisliste und nutzt die Tabelle, sobald vorhanden.
 */
class ModerationService
{
    /**
     * Eingebaute Basis-Sperrliste (bewusst knapp gehalten; erweiterbar über Seeder/DB).
     * Substring-Treffer auf normalisiertem Text.
     */
    public const BASE_TERMS = [
        // NS-/rassistische Begriffe (Basis, erweiterbar durch Lehrkräfte):
        'hitler', 'nazi', 'heil', 'hakenkreuz', 'sieg heil', 'judensau',
        'neger', 'nigger', 'nigga', 'kanake', 'schwuchtel', 'fotze', 'hurensohn',
        'wichser', 'missgeburt', 'schlampe', 'bastard', 'arschloch', 'ficken',
    ];

    public function isAllowed(string $input, ?int $userId = null): bool
    {
        return $this->firstMatch($input, $userId) === null;
    }

    /**
     * Ersten Treffer zurückgeben (oder null). Normalisiert gegen einfache Verschleierung.
     */
    public function firstMatch(string $input, ?int $userId = null): ?string
    {
        $normalized = $this->normalize($input);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->terms($userId) as $term) {
            $t = $this->normalize($term['term'] ?? $term);
            if ($t === '') {
                continue;
            }
            $type = is_array($term) ? ($term['match_type'] ?? 'substring') : 'substring';

            if ($type === 'exact' ? $normalized === $t : str_contains($normalized, $t)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{term:string, match_type:string}|string>
     */
    private function terms(?int $userId): array
    {
        $terms = array_map(fn ($t) => ['term' => $t, 'match_type' => 'substring'], self::BASE_TERMS);

        if (Schema::hasTable('blocklist_terms')) {
            $rows = \DB::table('blocklist_terms')
                ->where(function ($q) use ($userId) {
                    $q->whereNull('user_id');
                    if ($userId) {
                        $q->orWhere('user_id', $userId);
                    }
                })
                ->get(['term', 'match_type']);

            foreach ($rows as $row) {
                $terms[] = ['term' => $row->term, 'match_type' => $row->match_type ?? 'substring'];
            }
        }

        return $terms;
    }

    /**
     * Kleinschreibung, Leetspeak/Trennzeichen entschärfen.
     */
    public function normalize(string $value): string
    {
        $value = Str::lower(trim($value));
        $map = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's'];
        $value = strtr($value, $map);

        // Nicht-alphanumerische Zeichen entfernen (umgeht z.B. "n-a-z-i").
        return preg_replace('/[^a-z0-9äöüß]/u', '', $value) ?? '';
    }
}
