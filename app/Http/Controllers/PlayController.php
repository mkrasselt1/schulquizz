<?php

namespace App\Http\Controllers;

use App\Events\SessionEvent;
use App\Models\GameSession;
use App\Services\SessionService;
use App\Support\AnswerGrader;
use App\Support\EscapeRoom;
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
     * Escape-Room-Struktur (schülersicher) nachladen – erst ab Spielstart verfügbar.
     */
    public function escape(): JsonResponse
    {
        $participant = session('participant');
        abort_unless(is_array($participant), 403);

        $session = GameSession::where('session_key', $participant['session_key'])->with('game')->first();
        abort_unless($session && $session->game->isEscape(), 404);
        abort_unless(in_array($session->status, ['active', 'paused'], true), 409);

        return response()->json(EscapeRoom::forPlayers($session->game));
    }

    /**
     * Escape-Room: Rätsel-Antwort oder Schloss-Code prüfen.
     *
     * Zustandslos – es wird nichts gespeichert. Nur der Server kennt die Lösungen; bei Erfolg
     * gibt er die Belohnung zurück und meldet den Fortschritt flüchtig an die Lehrkraft.
     * Weil die Meldung vom Server kommt, kann ein Schüler keinen Fortschritt vortäuschen.
     */
    public function escapeCheck(Request $request): JsonResponse
    {
        $participant = session('participant');
        abort_unless(is_array($participant), 403);

        $data = $request->validate([
            'hotspot_id' => ['required', 'string', 'max:40'],
            'answer' => ['nullable'],
        ]);

        $session = GameSession::where('session_key', $participant['session_key'])->with('game')->first();
        abort_unless($session && $session->game->isEscape(), 404);

        if ($session->status !== 'active') {
            return response()->json(['ok' => false, 'reason' => $session->status], 409);
        }
        if ($session->question_deadline && $session->question_deadline->isPast()) {
            return response()->json(['ok' => false, 'reason' => 'time_up'], 409);
        }

        $found = EscapeRoom::findHotspot($session->game, $data['hotspot_id']);
        abort_unless($found && in_array($found['hotspot']['kind'], ['puzzle', 'lock'], true), 404);

        $hotspot = $found['hotspot'];
        $roomIndex = $found['room_index'];
        $roomCount = count(EscapeRoom::of($session->game)['rooms']);

        if ($hotspot['kind'] === 'puzzle') {
            $question = $hotspot['question_id']
                ? $session->game->questions()->with('answerOptions')->find($hotspot['question_id'])
                : null;
            $correct = $question !== null && AnswerGrader::isCorrect($question, $data['answer'] ?? null);
        } else {
            $correct = EscapeRoom::codeMatches($hotspot['code'], $data['answer'] ?? null);
        }

        // Was passiert bei Erfolg? Schloss → nächster Raum, im letzten Raum → entkommen.
        $result = null;
        if ($correct && $hotspot['kind'] === 'lock') {
            $result = $roomIndex + 1 >= $roomCount ? 'escaped' : 'next_room';
        }

        event(new SessionEvent(
            $participant['session_key'],
            'escape_progress',
            [
                'token' => $participant['token'],
                'label' => $participant['label'],
                'kind' => $participant['kind'],
                'hotspot_id' => $hotspot['id'],
                'hotspot_kind' => $hotspot['kind'],
                'room_index' => $result === 'next_room' ? $roomIndex + 1 : $roomIndex,
                'correct' => $correct,
                'result' => $result,
                'elapsed_ms' => $session->question_started_at
                    ? (int) $session->question_started_at->diffInMilliseconds(now())
                    : null,
            ],
            scope: 'teacher',
        ));

        return response()->json([
            'ok' => true,
            'correct' => $correct,
            'reward' => $correct ? ($hotspot['reward'] ?: null) : null,
            'result' => $result,
        ]);
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
