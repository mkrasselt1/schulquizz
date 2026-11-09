<?php

namespace App\Http\Controllers;

use App\Support\CompletionCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lehrkraft prüft Abschluss-Codes ihrer Escape-Rooms (z. B. aus dem Schul-Chat eingefügt).
 * Nichts wird gespeichert – die Prüfung ist reine Signaturkontrolle.
 */
class EscapeVerifyController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Escape/Verify');
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:5000'],
            'code' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        $parsed = CompletionCode::parseMessage((string) ($data['text'] ?? ''));
        $code = trim((string) ($data['code'] ?? '')) ?: $parsed['code'];
        $name = trim((string) ($data['name'] ?? '')) ?: $parsed['name'];

        if (! $code) {
            return response()->json(['valid' => false, 'reason' => 'no_code']);
        }
        if (! $name) {
            return response()->json(['valid' => false, 'reason' => 'no_name', 'code' => $code]);
        }

        $result = CompletionCode::verify($code, $name);
        if (! $result['valid']) {
            return response()->json(['valid' => false, 'reason' => $result['reason'], 'code' => $code, 'name' => $name]);
        }

        // Nur Codes zu EIGENEN Spielen anzeigen.
        $game = $request->user()->games()->find($result['game_id']);
        if (! $game) {
            return response()->json(['valid' => false, 'reason' => 'foreign_game', 'code' => $code, 'name' => $name]);
        }

        return response()->json([
            'valid' => true,
            'code' => $code,
            'name' => $name,
            'game_title' => $game->title,
            'elapsed_seconds' => $result['elapsed_seconds'],
            'finished_at' => $result['finished_at'],
        ]);
    }
}
