<?php

namespace App\Mcp\Tools;

use App\Mcp\PuzzleData;
use App\Support\GameImage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Setzt das Hintergrundbild eines Escape-Raums (room_id) oder das Bild eines Hinweis-Hotspots (zusätzlich hotspot_id, nur kind "clue"). Bild als "svg" (SVG-Quelltext, ideal für selbst gezeichnete Szenen; Skripte und externe Verweise werden entfernt, max. 512 KB) ODER als "image_base64" (PNG/JPEG/WebP/GIF, max. 10 MB). Tipp: Raumbilder im Querformat, z. B. viewBox="0 0 1600 900" – Hotspot-Koordinaten sind Prozent dieses Bildes.')]
class SetEscapeImage extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate([
            'game_id' => ['required', 'integer'],
            'room_id' => ['required', 'string'],
            'hotspot_id' => ['nullable', 'string'],
            'svg' => ['nullable', 'string', 'required_without:image_base64', 'prohibits:image_base64'],
            'image_base64' => ['nullable', 'string', 'required_without:svg'],
        ]);
        $game = PuzzleData::ownGame($request->user(), $request->get('game_id'));
        if (! $game->isEscape()) {
            throw ValidationException::withMessages(['game_id' => 'Dieses Spiel ist kein Escape-Room (mode "escape").']);
        }

        $svg = $request->get('svg');
        $base64 = $request->get('image_base64');
        $store = is_string($svg) && $svg !== ''
            ? fn () => GameImage::storeSvg($game, $svg)
            : function () use ($game, $base64) {
                // "data:image/png;base64,..." ebenfalls akzeptieren
                $data = preg_replace('#^data:[^,]*,#', '', trim((string) $base64));
                $binary = base64_decode(preg_replace('/\s+/', '', (string) $data), true);

                return $binary === false ? null : GameImage::storeRaster($game, $binary);
            };

        $game = PuzzleData::setEscapeImage($game, $request->get('room_id'), $request->get('hotspot_id'), $store);

        return Response::json(PuzzleData::exportEscape($game));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->integer()->required(),
            'room_id' => $schema->string()->description('ID des Raums (siehe get-game).')->required(),
            'hotspot_id' => $schema->string()->description('Optional: ID eines Hinweis-Hotspots (kind "clue") – dann wird dessen Bild gesetzt statt des Raum-Hintergrunds.'),
            'svg' => $schema->string()->description('SVG-Quelltext (<svg xmlns="http://www.w3.org/2000/svg" ...>).'),
            'image_base64' => $schema->string()->description('Rasterbild als Base64 (oder data:-URI).'),
        ];
    }
}
