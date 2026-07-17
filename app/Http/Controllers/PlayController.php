<?php

namespace App\Http\Controllers;

use App\Events\SessionEvent;
use App\Models\GameSession;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlayController extends Controller
{
    public function __construct(private readonly SessionService $sessions)
    {
    }

    /**
     * Spielansicht des Schülers. Erfordert eine gültige Teilnehmer-Session (Cookie).
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $participant = session('participant');
        if (! is_array($participant)) {
            return redirect()->route('join.pin');
        }

        $session = GameSession::where('session_key', $participant['session_key'])->first();
        if (! $session || ! $session->isLive()) {
            $request->session()->forget('participant');

            return redirect()->route('join.pin')
                ->withErrors(['pin' => 'Das Spiel ist nicht mehr aktiv.']);
        }

        return Inertia::render('Play/Play', [
            'participant' => [
                'token' => $participant['token'],
                'label' => $participant['label'],
                'kind' => $participant['kind'],
            ],
            'sessionKey' => $session->session_key,
            'state' => $this->sessions->stateFor($session),
        ]);
    }

    /**
     * Antwort abgeben – NUR flüchtig per WebSocket an die Lehrkraft, keine DB-Speicherung.
     */
    public function answer(Request $request): JsonResponse
    {
        $participant = session('participant');
        abort_unless(is_array($participant), 403);

        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            'answer' => ['nullable'],
            'response_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        event(new SessionEvent(
            $participant['session_key'],
            'answer',
            [
                'token' => $participant['token'],
                'label' => $participant['label'],
                'kind' => $participant['kind'],
                'question_id' => $data['question_id'],
                'answer' => $data['answer'] ?? null,
                'response_ms' => $data['response_ms'] ?? null,
            ],
            scope: 'teacher',
        ));

        return response()->json(['ok' => true]);
    }

    /**
     * Fokus-Ereignis (Vollbild verlassen / Tab-Wechsel) – flüchtig an die Lehrkraft.
     */
    public function focus(Request $request): JsonResponse
    {
        $participant = session('participant');
        abort_unless(is_array($participant), 403);

        $data = $request->validate([
            'type' => ['required', 'in:tab_hidden,window_blur,fullscreen_exit,returned'],
        ]);

        event(new SessionEvent(
            $participant['session_key'],
            'focus',
            [
                'token' => $participant['token'],
                'label' => $participant['label'],
                'type' => $data['type'],
            ],
            scope: 'teacher',
        ));

        return response()->json(['ok' => true]);
    }
}
