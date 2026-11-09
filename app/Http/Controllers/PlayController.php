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
     * Aktuellen Zustand nachladen – nach Verbindungsabbruch, Bildschirmsperre, Tab-Wechsel.
     * (Broadcasts, die während eines Aussetzers kamen, sind sonst verloren.)
     */
    public function state(): JsonResponse
    {
        $participant = session('participant');
        abort_unless(is_array($participant), 403);

        $session = GameSession::where('session_key', $participant['session_key'])->first();
        if (! $session || ! $session->isLive()) {
            return response()->json(['status' => 'ended']);
        }

        return response()->json($this->sessions->stateFor($session, false));
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

        $session = GameSession::where('session_key', $participant['session_key'])->first();
        abort_unless($session !== null, 404);

        // Nur die aktuelle Frage, nur in der Antwortphase, nur bis kurz nach Zeitablauf.
        // Verhindert späte/doppelte Antworten nach der Auflösung (z. B. nach Neuladen).
        $open = $session->status === 'active'
            && (int) $session->current_question_id === (int) $data['question_id']
            && $session->current_phase === 'answers'
            && (! $session->question_deadline || $session->question_deadline->copy()->addSeconds(3)->isFuture());
        if (! $open) {
            return response()->json(['ok' => false, 'reason' => 'closed'], 409);
        }

        // Antwortzeit vom Server messen (Client-Wert ist manipulierbar und nach Neuladen falsch).
        $responseMs = $session->question_started_at
            ? max(0, (int) $session->question_started_at->diffInMilliseconds(now()))
            : ($data['response_ms'] ?? null);

        SessionEvent::send(
            $participant['session_key'],
            'answer',
            [
                'token' => $participant['token'],
                'label' => $participant['label'],
                'kind' => $participant['kind'],
                'question_id' => $data['question_id'],
                'answer' => $data['answer'] ?? null,
                'response_ms' => $responseMs,
            ],
            scope: 'teacher',
        );

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

        $elapsed = $session->question_started_at
            ? (int) $session->question_started_at->diffInMilliseconds(now())
            : null;

        // Was passiert bei Erfolg? Schloss → nächster Raum, im letzten Raum → entkommen.
        $result = null;
        if ($correct && $hotspot['kind'] === 'lock') {
            $result = $roomIndex + 1 >= $roomCount ? 'escaped' : 'next_room';
        }

        SessionEvent::send(
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
                'elapsed_ms' => $elapsed,
            ],
            scope: 'teacher',
        );

        $receipt = null;
        if ($correct) {
            $receipt = [
                'hotspot_id' => $hotspot['id'],
                'hotspot_kind' => $hotspot['kind'],
                'room_index' => $result === 'next_room' ? $roomIndex + 1 : $roomIndex,
                'result' => $result,
                'elapsed_ms' => $elapsed,
            ];
            $receipt['sig'] = $this->sign($participant, $receipt);
        }

        return response()->json([
            'ok' => true,
            'correct' => $correct,
            'reward' => $correct ? ($hotspot['reward'] ?: null) : null,
            'result' => $result,
            // Signierte Quittung: Damit kann der Schüler-Browser seinen Fortschritt der
            // Lehrkraft erneut melden (z. B. nachdem deren Seite neu geladen wurde) –
            // fälschungssicher, ohne dass der Server etwas speichert.
            'receipt' => $receipt,
        ]);
    }

    /**
     * Escape-Fortschritt erneut melden (nach Neuladen des Leitstands). Nur gültig
     * signierte Quittungen werden weitergegeben.
     */
    public function escapeResync(Request $request): JsonResponse
    {
        $participant = session('participant');
        abort_unless(is_array($participant), 403);

        $data = $request->validate([
            'receipts' => ['present', 'array', 'max:200'],
            'receipts.*.hotspot_id' => ['required', 'string', 'max:40'],
            'receipts.*.hotspot_kind' => ['required', 'string', 'max:10'],
            'receipts.*.room_index' => ['required', 'integer', 'min:0'],
            'receipts.*.result' => ['nullable', 'string', 'max:20'],
            'receipts.*.elapsed_ms' => ['nullable', 'integer', 'min:0'],
            'receipts.*.sig' => ['required', 'string', 'max:128'],
        ]);

        $accepted = 0;
        foreach ($data['receipts'] as $r) {
            $receipt = [
                'hotspot_id' => $r['hotspot_id'],
                'hotspot_kind' => $r['hotspot_kind'],
                'room_index' => (int) $r['room_index'],
                'result' => $r['result'] ?? null,
                'elapsed_ms' => isset($r['elapsed_ms']) ? (int) $r['elapsed_ms'] : null,
            ];
            if (! hash_equals($this->sign($participant, $receipt), $r['sig'])) {
                continue;
            }
            $accepted++;
            SessionEvent::send($participant['session_key'], 'escape_progress', [
                'token' => $participant['token'],
                'label' => $participant['label'],
                'kind' => $participant['kind'],
                ...$receipt,
                'correct' => true,
            ], scope: 'teacher');
        }

        return response()->json(['ok' => true, 'accepted' => $accepted]);
    }

    private function sign(array $participant, array $receipt): string
    {
        return hash_hmac('sha256', implode('|', [
            $participant['session_key'],
            $participant['token'],
            $receipt['hotspot_id'],
            $receipt['hotspot_kind'],
            $receipt['room_index'],
            $receipt['result'] ?? '',
            $receipt['elapsed_ms'] ?? '',
        ]), (string) config('app.key'));
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

        SessionEvent::send(
            $participant['session_key'],
            'focus',
            [
                'token' => $participant['token'],
                'label' => $participant['label'],
                'type' => $data['type'],
            ],
            scope: 'teacher',
        );

        return response()->json(['ok' => true]);
    }
}
