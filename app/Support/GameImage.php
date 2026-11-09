<?php

namespace App\Support;

use App\Models\Game;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bilder eines Spiels ablegen (public-Disk, Ordner game-images/{id}), ohne Datei-Upload:
 * aus Rohdaten (z. B. Base64 über die MCP-Schnittstelle oder den Import).
 */
class GameImage
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const RASTER_TYPES = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    /** PNG/JPEG/WebP/GIF speichern – nur wenn die Daten wirklich ein solches Bild sind. */
    public static function storeRaster(Game $game, string $binary): ?string
    {
        if ($binary === '' || strlen($binary) > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($binary);
        $ext = $info ? (self::RASTER_TYPES[$info[2]] ?? null) : null;
        if (! $ext || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return self::put($game, $binary, $ext);
    }

    /** SVG bereinigen (siehe SvgSanitizer) und speichern. */
    public static function storeSvg(Game $game, string $svg): ?string
    {
        $clean = SvgSanitizer::clean($svg);

        return $clean === null ? null : self::put($game, $clean, 'svg');
    }

    private static function put(Game $game, string $contents, string $ext): string
    {
        $path = "game-images/{$game->id}/".Str::random(20).'.'.$ext;
        Storage::disk('public')->put($path, $contents);

        return $path;
    }
}
