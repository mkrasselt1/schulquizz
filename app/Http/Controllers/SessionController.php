<?php

namespace App\Http\Controllers;

use App\Http\Resources\QuestionResource;
use App\Models\Game;
use App\Models\GameSession;
use App\Services\SessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions)
    {
    }

    /**
     * Session starten (mit Beitrittsmethode/Parameter-Override) und zum Leitstand wechseln.
     */
    public function store(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('update', $game);

        $data = $request->validate([
            'join_method' => ['required', 'in:pin,qr'],
            'advance_mode' => ['required', 'in:teacher,free'],
            'settings' => ['nullable', 'array'],
        ]);

        $session = $this->sessions->start($game, $data);

        return redirect()->route('sessions.control', $session);
    }

    public function control(GameSession $session): Response
    {
        $this->authorizeSession($session);
        $session->load(['game.questions.answerOptions']);

        return Inertia::render('Sessions/Control', [
            'session' => [
                'id' => $session->id,
                'session_key' => $session->session_key,
                'pin' => $session->pin,
                'join_method' => $session->join_method,
                'advance_mode' => $session->advance_mode,
                'status' => $session->status,
                'join_url' => $session->pin
                    ? url('/join')
                    : url('/j/'.$session->game->join_token),
                'board_url' => url('/board/'.$session->session_key),
            ],
            'game' => [
                'id' => $session->game->id,
                'title' => $session->game->title,
                'join_token' => $session->game->join_token,
                'base_locale' => $session->game->base_locale,
            ],
            'state' => $this->sessions->stateFor($session),
            // Vollständige Fragen inkl. Lösungen – nur für die (autorisierte) Lehrkraft,
            // damit die Wertung lokal im Browser läuft.
            'questions' => QuestionResource::collection($session->game->questions)->resolve(),
        ]);
    }

    public function pause(GameSession $session): RedirectResponse
    {
        $this->authorizeSession($session);
        $this->sessions->pause($session);

        return back();
    }

    public function resume(GameSession $session): RedirectResponse
    {
        $this->authorizeSession($session);
        $this->sessions->resume($session);

        return back();
    }

    public function end(GameSession $session): RedirectResponse
    {
        $this->authorizeSession($session);
        $this->sessions->end($session);

        // Auf dem Leitstand bleiben (Podium/Export anzeigen); Status kommt via Broadcast.
        return back();
    }

    /**
     * Frage anzeigen: per direction (next/prev) oder konkreter question_id.
     */
    public function question(Request $request, GameSession $session): RedirectResponse
    {
        $this->authorizeSession($session);

        $data = $request->validate([
            'direction' => ['nullable', 'in:next,prev'],
            'question_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['question_id'])) {
            $question = $session->game->questions()->findOrFail($data['question_id']);
            $this->sessions->showQuestion($session, $question);
        } else {
            $this->sessions->move($session, ($data['direction'] ?? 'next') === 'prev' ? -1 : 1);
        }

        return back();
    }

    public function phase(GameSession $session): RedirectResponse
    {
        $this->authorizeSession($session);
        $this->sessions->advancePhase($session);

        return back();
    }

    public function timer(Request $request, GameSession $session): RedirectResponse
    {
        $this->authorizeSession($session);

        $data = $request->validate([
            'mode' => ['required', 'in:set,extend'],
            'seconds' => ['required', 'integer', 'min:1', 'max:3600'],
        ]);

        if ($data['mode'] === 'set') {
            $this->sessions->setTimer($session, $data['seconds']);
        } else {
            $this->sessions->extendTimer($session, $data['seconds']);
        }

        return back();
    }

    public function reveal(GameSession $session): RedirectResponse
    {
        $this->authorizeSession($session);
        $this->sessions->reveal($session);

        return back();
    }

    /**
     * Aggregat (anonyme Verteilung / Rangliste / Podium) vom Lehrer-Browser an die Tafel spiegeln.
     * Der Server relayed nur – keine Speicherung von Schülerdaten.
     */
    public function board(Request $request, GameSession $session): \Illuminate\Http\JsonResponse
    {
        $this->authorizeSession($session);

        $data = $request->validate([
            'event' => ['required', 'in:distribution,leaderboard,podium,clear'],
            'payload' => ['nullable', 'array'],
        ]);

        event(new \App\Events\SessionEvent(
            $session->session_key,
            'board_'.$data['event'],
            $data['payload'] ?? [],
            scope: 'board',
        ));

        return response()->json(['ok' => true]);
    }

    private function authorizeSession(GameSession $session): void
    {
        abort_unless($session->user_id === auth()->id(), 403);
    }
}
