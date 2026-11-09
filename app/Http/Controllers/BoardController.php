<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Services\SessionService;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function __construct(private readonly SessionService $sessions)
    {
    }

    /**
     * Öffentliche Tafel-/Beamer-Ansicht (kein Login; unratbarer session_key).
     */
    public function show(string $key, SessionService $sessions): Response
    {
        $session = GameSession::where('session_key', $key)->firstOrFail();
        $session->load('game');

        return Inertia::render('Board', [
            'sessionKey' => $session->session_key,
            'gameTitle' => $session->game->title,
            'joinMethod' => $session->join_method,
            'pin' => $session->pin,
            'joinUrl' => $session->pin
                ? url('/join')
                : url('/j/'.$session->game->join_token),
            'state' => $this->sessions->stateFor($session, false),
            'lastBoard' => \Illuminate\Support\Facades\Cache::get("board-last:{$session->session_key}"),
        ]);
    }

    /** Zustand nachladen (Tafel-Browser nach Verbindungsabbruch). */
    public function state(string $key): \Illuminate\Http\JsonResponse
    {
        $session = GameSession::where('session_key', $key)->firstOrFail();

        return response()->json([
            'state' => $this->sessions->stateFor($session, false),
            'lastBoard' => \Illuminate\Support\Facades\Cache::get("board-last:{$session->session_key}"),
        ]);
    }
}
