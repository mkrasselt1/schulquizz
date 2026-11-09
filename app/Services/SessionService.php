<?php

namespace App\Services;

use App\Events\SessionEvent;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\Question;
use App\Support\EscapeRoom;
use App\Support\PublicQuestion;
use Illuminate\Support\Carbon;

/**
 * Kapselt alle Zustandsübergänge einer Live-Session inkl. Realtime-Broadcasts.
 * Persistiert NUR den Session-Fortschritt – niemals Schülerdaten.
 */
class SessionService
{
    /**
     * Neue Session starten. Beendet zuvor eine ggf. laufende Session desselben Spiels
     * (max. eine aktive Session pro Spiel/Lehrer).
     */
    public function start(Game $game, array $overrides = []): GameSession
    {
        if ($existing = GameSession::activeForGame($game)) {
            $this->end($existing);
        }

        $settings = array_merge(
            Game::DEFAULT_SETTINGS,
            $game->settings ?? [],
            $overrides['settings'] ?? [],
        );

        $joinMethod = $overrides['join_method'] ?? 'qr';

        $session = new GameSession([
            'join_method' => $joinMethod,
            'status' => 'lobby',
            'advance_mode' => $overrides['advance_mode'] ?? ($settings['advance_mode'] ?? 'teacher'),
            'settings' => $settings,
        ]);
        $session->game_id = $game->id;
        $session->user_id = $game->user_id;
        $session->pin = $joinMethod === 'pin' ? GameSession::generatePin() : null;
        $session->started_at = Carbon::now();
        $session->save();

        return $session;
    }

    /**
     * Escape-Room starten: alle Teilnehmenden spielen ab jetzt selbstständig.
     * Gesamtzeit läuft über question_deadline (verlängerbar wie beim Quiz).
     */
    public function startEscape(GameSession $session): void
    {
        $limit = EscapeRoom::of($session->game)['time_limit'] ?? null;

        $session->status = 'active';
        $session->current_question_id = null;
        $session->current_phase = 'escape';
        $session->question_started_at = Carbon::now();
        $session->question_deadline = $limit ? Carbon::now()->addMinutes((int) $limit) : null;
        $session->save();

        $this->broadcastState($session);
    }

    public function pause(GameSession $session): void
    {
        if ($session->status === 'paused') {
            return;
        }
        // Restzeit einfrieren – sonst läuft die Zeit während der Pause weiter.
        $remaining = $session->question_deadline && $session->question_deadline->isFuture()
            ? (int) Carbon::now()->diffInMilliseconds($session->question_deadline)
            : null;
        $session->update([
            'status' => 'paused',
            'paused_remaining_ms' => $remaining,
            'question_deadline' => $remaining !== null ? null : $session->question_deadline,
        ]);
        $this->broadcastState($session);
    }

    public function resume(GameSession $session): void
    {
        $attrs = ['status' => 'active', 'paused_remaining_ms' => null];
        if ($session->paused_remaining_ms !== null) {
            $attrs['question_deadline'] = Carbon::now()->addMilliseconds((int) $session->paused_remaining_ms);
        }
        $session->update($attrs);
        $this->broadcastState($session);
    }

    /**
     * Escape-Room dauerhaft öffnen: eine Session ohne gemeinsame Uhr – jede:r Schüler:in hat
     * ab dem eigenen Start die volle Zeit. Beitritt über den festen QR-Code/Link.
     */
    public function openPermanent(Game $game): GameSession
    {
        $running = GameSession::activeForGame($game);
        if ($running && ($running->settings['permanent'] ?? false)) {
            return $running;
        }
        if ($running) {
            throw new \RuntimeException('running');
        }

        $session = $this->start($game, [
            'join_method' => 'qr',
            'advance_mode' => 'free',
            'settings' => ['permanent' => true],
        ]);
        $session->status = 'active';
        $session->current_phase = 'escape';
        $session->question_started_at = Carbon::now();
        $session->question_deadline = null;
        $session->save();

        return $session;
    }

    public function closePermanent(Game $game): void
    {
        $running = GameSession::activeForGame($game);
        if ($running && ($running->settings['permanent'] ?? false)) {
            $this->end($running);
        }
    }

    public function end(GameSession $session): void
    {
        // Dauerhaft geöffneten Raum beenden = Schalter im Editor ebenfalls aus.
        if (($session->settings['permanent'] ?? false) && $session->game?->escape) {
            $game = $session->game;
            $game->escape = [...$game->escape, 'always_open' => false];
            $game->save();
        }
        $session->update(['status' => 'ended', 'ended_at' => Carbon::now()]);
        SessionEvent::send($session->session_key, 'ended', ['message' => 'Spiel beendet']);
    }

    /**
     * Eine bestimmte Frage anzeigen (setzt Phase & Timer, broadcastet an Schüler + Board).
     */
    public function showQuestion(GameSession $session, Question $question): void
    {
        $twoPhase = (bool) ($session->settings['two_phase'] ?? false);
        $phase = $twoPhase ? 'prompt' : 'answers';

        $session->status = 'active';
        $session->current_question_id = $question->id;
        $session->current_phase = $phase;
        $session->question_started_at = Carbon::now();
        $session->question_deadline = $question->time_limit
            ? Carbon::now()->addSeconds($question->time_limit)
            : null;
        $session->save();

        $this->broadcastState($session);
    }

    /**
     * Nächste/vorherige Frage nach Position.
     */
    public function move(GameSession $session, int $direction): ?Question
    {
        $questions = $session->game->questions()->get();
        $currentIndex = $questions->search(fn ($q) => $q->id === $session->current_question_id);
        $nextIndex = $currentIndex === false ? 0 : $currentIndex + $direction;

        $target = $questions->get($nextIndex);
        if ($target) {
            $this->showQuestion($session, $target);
        }

        return $target;
    }

    /**
     * Zweite Phase freischalten (Frage → Antworten).
     */
    public function advancePhase(GameSession $session): void
    {
        if ($session->current_phase === 'prompt') {
            // Antwortzeit ab Freigabe der Antworten messen.
            $session->update(['current_phase' => 'answers', 'question_started_at' => Carbon::now()]);
            $this->broadcastState($session);
        }
    }

    /** Serverzeit mit Millisekunden – Clients gleichen damit ihre Uhr ab. */
    public static function serverTime(): string
    {
        return Carbon::now()->format('Y-m-d\TH:i:s.vP');
    }

    public function setTimer(GameSession $session, int $seconds): void
    {
        $session->update([
            'question_deadline' => Carbon::now()->addSeconds($seconds),
        ]);
        SessionEvent::send($session->session_key, 'timer', [
            'deadline' => $session->question_deadline?->toIso8601String(),
            'server_time' => self::serverTime(),
        ]);
    }

    public function extendTimer(GameSession $session, int $seconds): void
    {
        $base = $session->question_deadline && $session->question_deadline->isFuture()
            ? $session->question_deadline
            : Carbon::now();
        $session->update(['question_deadline' => $base->addSeconds($seconds)]);
        SessionEvent::send($session->session_key, 'timer', [
            'deadline' => $session->question_deadline?->toIso8601String(),
            'server_time' => self::serverTime(),
        ]);
    }

    /**
     * Auflösung broadcasten (richtige Antwort + optional anonyme Verteilung baut der Client).
     */
    public function reveal(GameSession $session): void
    {
        $question = $session->currentQuestion;
        if (! $question) {
            return;
        }
        $session->update(['current_phase' => 'reveal']);

        SessionEvent::send($session->session_key, 'reveal', [
            'reveal' => PublicQuestion::reveal($question),
            'reveal_mode' => $session->settings['reveal_mode'] ?? 'correct',
        ]);
    }

    /**
     * Vollständigen Session-Zustand an Schüler (Presence) + Board senden.
     */
    public function broadcastState(GameSession $session): void
    {
        // Raumstruktur nicht mitsenden (Nachrichtengröße) – Clients laden sie per HTTP nach.
        SessionEvent::send($session->session_key, 'state', $this->stateFor($session, false));
    }

    /**
     * Öffentlicher Zustand (schülersicher). Auch für Initial-Load von Play/Board nutzbar.
     */
    public function stateFor(GameSession $session, bool $withEscape = true): array
    {
        $question = $session->currentQuestion;
        $questions = $session->game->questions()->get();
        $index = $question ? $questions->search(fn ($q) => $q->id === $question->id) : false;

        $withAnswers = $session->current_phase !== 'prompt';
        $isEscape = $session->game->isEscape();

        return [
            'server_time' => self::serverTime(),
            'mode' => $isEscape ? 'escape' : 'quiz',
            'permanent' => (bool) ($session->settings['permanent'] ?? false),
            // Raumstruktur erst nach dem Start (keine Vorschau in der Lobby).
            'escape' => $withEscape && $isEscape && in_array($session->status, ['active', 'paused'], true)
                ? EscapeRoom::forPlayers($session->game)
                : null,
            'escape_room_count' => $isEscape ? count(EscapeRoom::of($session->game)['rooms']) : 0,
            'started_at' => $session->question_started_at?->toIso8601String(),
            'status' => $session->status,
            'advance_mode' => $session->advance_mode,
            'phase' => $session->current_phase,
            'question_index' => $index === false ? null : $index,
            'question_total' => $questions->count(),
            'deadline' => $session->question_deadline?->toIso8601String(),
            'paused_remaining_ms' => $session->paused_remaining_ms,
            'require_fullscreen' => (bool) ($session->settings['require_fullscreen'] ?? false),
            'question' => $question
                ? PublicQuestion::forPlayers($question, $withAnswers)
                : null,
        ];
    }
}
