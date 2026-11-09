<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Support\EscapeRoom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EscapeController extends Controller
{
    /**
     * Raum-/Hotspot-Struktur eines Escape-Rooms speichern (voller Payload, normalisiert).
     */
    public function update(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('update', $game);
        abort_unless($game->isEscape(), 422);

        $data = $request->validate([
            'escape' => ['required', 'array'],
            'escape.rooms' => ['required', 'array', 'min:1', 'max:20'],
            'escape.rooms.*.hotspots' => ['nullable', 'array', 'max:60'],
            'escape.time_limit' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $game->escape = EscapeRoom::sanitize($data['escape'], $game);
        $game->save();

        return back()->with('status', 'escape-saved');
    }
}
