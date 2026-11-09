<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameSession;
use App\Services\EmojiPoolService;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class JoinController extends Controller
{
    public function __construct(
        private readonly EmojiPoolService $emojis,
        private readonly ModerationService $moderation,
    ) {
    }

    /**
     * Fester QR-Einstieg: /j/{token}. Zeigt Beitritt, wenn eine Session läuft, sonst Warteseite.
     */
    public function viaToken(string $token): Response
    {
        $game = Game::where('join_token', $token)->firstOrFail();
        $session = GameSession::activeForGame($game);

        return Inertia::render('Play/Join', [
            'mode' => 'qr',
            'joinToken' => $token,
            'gameTitle' => $game->title,
            'active' => (bool) $session,
        ]);
    }

    /**
     * PIN-Einstieg: /join (Schüler tippt PIN).
     */
    public function viaPin(): Response
    {
        return Inertia::render('Play/Join', [
            'mode' => 'pin',
            'active' => true,
        ]);
    }

    /**
     * Poll-Status für die Warteseite (QR ohne laufende Session).
     */
    public function status(string $token): JsonResponse
    {
        $game = Game::where('join_token', $token)->firstOrFail();

        return response()->json(['active' => (bool) GameSession::activeForGame($game)]);
    }

    /**
     * Beitritt abschließen: Teilnehmer-Session (Cookie) setzen, dann zum Spiel.
     */
    public function join(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['nullable', 'string', 'size:6'],
            'join_token' => ['nullable', 'string'],
            'display_type' => ['required', 'in:name,emoji'],
            'name' => ['nullable', 'string', 'max:30'],
            'participant_token' => ['nullable', 'string', 'max:64'],
            'reconnect_emoji' => ['nullable', 'string', 'max:16'],
        ]);

        $session = $this->resolveSession($data);
        if (! $session) {
            return back()->withErrors(['pin' => 'Kein laufendes Spiel gefunden.']);
        }

        $previous = session('participant');
        $sameSession = is_array($previous) && ($previous['session_key'] ?? null) === $session->session_key;

        // Label bestimmen (Name gegen Sperrliste, sonst Auto-Emoji).
        if ($data['display_type'] === 'name') {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                return back()->withErrors(['name' => 'Bitte einen Namen eingeben.']);
            }
            if (! $this->moderation->isAllowed($name, $session->user_id)) {
                return back()->withErrors(['name' => 'Bitte wähle einen anderen Namen.']);
            }
            $label = $name;
            $kind = 'name';
        } else {
            // Reconnect (gleiches Gerät, gleiches Spiel): Emoji behalten, sonst neues eindeutiges.
            $label = $sameSession && ($previous['kind'] ?? null) === 'emoji'
                ? $previous['label']
                : $this->emojis->assign($session->session_key);
            $kind = 'emoji';
        }

        // Token nur wiederverwenden, wenn DIESES Gerät schon in DIESEM Spiel war (serverseitige
        // Session). Vom Browser mitgeschickte Daten werden nicht vertraut – sonst übernimmt auf
        // einem geteilten Klassen-Tablet der nächste Schüler Identität und Punkte des vorigen.
        $token = $sameSession ? $previous['token'] : (string) Str::uuid();

        session([
            'participant' => [
                'session_key' => $session->session_key,
                'token' => $token,
                'label' => $label,
                'kind' => $kind,
            ],
        ]);

        // Roster-Meldung an die Lehrkraft (kein Presence-Channel nötig).
        \App\Events\SessionEvent::send(
            $session->session_key,
            'joined',
            ['token' => $token, 'label' => $label, 'kind' => $kind],
            scope: 'teacher',
        );

        return redirect()->route('play');
    }

    public function leave(Request $request): RedirectResponse
    {
        $participant = session('participant');
        if (is_array($participant)) {
            if (($participant['kind'] ?? null) === 'emoji') {
                $this->emojis->release($participant['session_key'], $participant['label']);
            }
            \App\Events\SessionEvent::send(
                $participant['session_key'],
                'left',
                ['token' => $participant['token']],
                scope: 'teacher',
            );
        }
        $request->session()->forget('participant');

        return redirect('/');
    }

    private function resolveSession(array $data): ?GameSession
    {
        if (! empty($data['join_token'])) {
            $game = Game::where('join_token', $data['join_token'])->first();

            return $game ? GameSession::activeForGame($game) : null;
        }

        if (! empty($data['pin'])) {
            return GameSession::whereIn('status', ['lobby', 'active', 'paused'])
                ->where('pin', $data['pin'])
                ->latest('id')
                ->first();
        }

        return null;
    }
}
