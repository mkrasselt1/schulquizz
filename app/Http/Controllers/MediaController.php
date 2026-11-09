<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    /**
     * Bild für eine Frage/Antwort hochladen. Liegt auf der 'public'-Disk,
     * damit es lokal ausgeliefert werden kann (keine externen Dienste).
     */
    public function store(Request $request, Game $game): JsonResponse
    {
        $this->authorize('update', $game);

        $request->validate([
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:10240'], // 10 MB (Handyfotos)
        ]);

        $path = $request->file('image')->store("game-images/{$game->id}", 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
