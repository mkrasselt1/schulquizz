<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Abschluss-Code für Escape-Rooms ("Nachweis"): kurz, kopierbar, einzigartig, fälschungssicher –
 * und ohne dass der Server irgendetwas speichert.
 *
 * Inhalt (17 Byte → 28 Zeichen Base32, Format ESC-XXXX-XXXX-…):
 *   Spiel-ID (3) · Abschluss-Minute seit 2025 (3) · benötigte Sekunden (3) · Zufall (2) · Signatur (6)
 * Die Signatur (HMAC mit dem App-Schlüssel) deckt zusätzlich den NAMEN ab: Ein Code passt nur zu
 * dem Namen, mit dem gespielt wurde – Weitergeben an Mitschüler funktioniert nicht.
 */
class CompletionCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford (ohne I, L, O, U)

    private const EPOCH = 1735689600; // 2025-01-01 00:00 UTC

    public static function issue(int $gameId, string $name, int $elapsedMs, ?Carbon $finishedAt = null): string
    {
        $finishedAt ??= Carbon::now();
        $payload = self::int3($gameId)
            .self::int3(max(0, intdiv($finishedAt->getTimestamp() - self::EPOCH, 60)))
            .self::int3(min(0xFFFFFF, max(0, intdiv($elapsedMs, 1000))))
            .random_bytes(2);

        return self::format(self::base32($payload.self::sign($payload, $name)));
    }

    /**
     * @return array{valid: bool, reason?: string, game_id?: int, elapsed_seconds?: int, finished_at?: string}
     */
    public static function verify(string $code, string $name): array
    {
        $bytes = self::decode($code);
        if ($bytes === null || strlen($bytes) !== 17) {
            return ['valid' => false, 'reason' => 'format'];
        }
        $payload = substr($bytes, 0, 11);
        if (! hash_equals(self::sign($payload, $name), substr($bytes, 11, 6))) {
            return ['valid' => false, 'reason' => 'signature'];
        }

        return [
            'valid' => true,
            'game_id' => self::readInt3($payload, 0),
            'finished_at' => Carbon::createFromTimestamp(self::EPOCH + self::readInt3($payload, 3) * 60)->toIso8601String(),
            'elapsed_seconds' => self::readInt3($payload, 6),
        ];
    }

    /** Aus einer eingefügten Nachricht Code und Namen herausziehen. */
    public static function parseMessage(string $text): array
    {
        $code = null;
        if (preg_match('/ESC[\s-]*((?:[0-9A-Za-z]{4}[\s-]*){7})/u', $text, $m)) {
            $code = 'ESC-'.$m[1];
        }
        $name = null;
        if (preg_match('/^\s*Name\s*:\s*(.+?)\s*$/mu', $text, $m)) {
            $name = $m[1];
        }

        return ['code' => $code, 'name' => $name];
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }

    private static function sign(string $payload, string $name): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', 'escape-completion|'.$payload.'|'.self::normalizeName($name), $key, true), 0, 6);
    }

    private static function int3(int $n): string
    {
        $n = max(0, min(0xFFFFFF, $n));

        return chr(($n >> 16) & 0xFF).chr(($n >> 8) & 0xFF).chr($n & 0xFF);
    }

    private static function readInt3(string $s, int $offset): int
    {
        return (ord($s[$offset]) << 16) | (ord($s[$offset + 1]) << 8) | ord($s[$offset + 2]);
    }

    private static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 5) * 5, '0');
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    private static function decode(string $code): ?string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        $clean = preg_replace('/^ESC/', '', $clean);
        $clean = strtr($clean, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
        if ($clean === '' || strlen($clean) !== 28) {
            return null;
        }
        $bits = '';
        foreach (str_split($clean) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                return null;
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split(substr($bits, 0, 136), 8) as $byte) {
            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }

    private static function format(string $b32): string
    {
        return 'ESC-'.implode('-', str_split($b32, 4));
    }
}
