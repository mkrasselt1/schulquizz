<?php

use App\Models\GameSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Privater Lehrer-Channel: nur die Eigentümer-Lehrkraft. Empfängt Antworten, Fokus-Alerts
 * und Beitritts-/Verlassen-Meldungen (Roster).
 */
Broadcast::channel('session.{key}.teacher', function ($user, string $key) {
    $session = GameSession::where('session_key', $key)->first();

    return (bool) ($user && $session && $session->user_id === $user->id);
});

// Schüler-Channel `session.{key}` und Board-Channel `board.{key}` sind ÖFFENTLICH
// (unratbarer session_key) → keine Autorisierung nötig, keine Schülerkonten erforderlich.
