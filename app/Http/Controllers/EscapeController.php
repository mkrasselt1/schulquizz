<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\SessionService;
use App\Support\EscapeRoom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EscapeController extends Controller
{
    /**
     * Raum-/Hotspot-Struktur eines Escape-Rooms speichern (voller Payload, normalisiert).
     */
    public function update(Request $request, Game $game, SessionService $sessions): RedirectResponse
    {
        $this->authorize('update', $game);
        abort_unless($game->isEscape(), 422);

        $request->validate([
            'escape' => ['required', 'array'],
            'escape.rooms' => ['required', 'array', 'min:1', 'max:20'],
            'escape.rooms.*.hotspots' => ['nullable', 'array', 'max:60'],
            'escape.time_limit' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        // Bewusst die ROHE Eingabe: validate() liefert bei verschachtelten Regeln nur Felder
        // mit eigener Regel zurück (Raum-Titel, Bild, Intro … würden verloren gehen).
        // Die vollständige Normalisierung/Absicherung übernimmt sanitize().
        $escape = EscapeRoom::sanitize((array) $request->input('escape'), $game);

        // "Jederzeit spielbar": dauerhafte Session öffnen bzw. schließen.
        if ($escape['always_open']) {
            try {
                $game->escape = $escape;
                $game->save();
                $sessions->openPermanent($game);
            } catch (\RuntimeException) {
                $escape['always_open'] = false;
                $game->escape = $escape;
                $game->save();

                return back()->withErrors(['always_open' => 'Gerade läuft ein Spiel mit der Klasse. Bitte erst beenden, dann dauerhaft öffnen.']);
            }
        } else {
            $game->escape = $escape;
            $game->save();
            $sessions->closePermanent($game);
        }

        return back()->with('status', 'escape-saved');
    }
}
