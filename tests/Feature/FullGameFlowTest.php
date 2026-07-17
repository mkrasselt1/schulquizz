<?php

namespace Tests\Feature;

use App\Events\SessionEvent;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Durchgängiger Spielablauf über die HTTP-Endpunkte (Backend-Sicht).
 * Der Realtime-/Presence-Teil ist clientseitig; hier werden die Broadcasts geprüft.
 */
class FullGameFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_start_join_answer_reveal_end(): void
    {
        Event::fake([SessionEvent::class]);

        // Lehrer + Spiel mit einer Choice-Frage
        $teacher = User::factory()->create();
        $game = new Game();
        $game->user_id = $teacher->id;
        $game->title = 'Durchlauf';
        $game->save();
        $game->refresh();
        $question = QuestionDefaults::create($game, 'choice', 0);

        // 1) Session starten (QR)
        $this->actingAs($teacher)->post("/games/{$game->id}/session", [
            'join_method' => 'qr',
            'advance_mode' => 'teacher',
        ])->assertRedirect();
        $session = GameSession::activeForGame($game);
        $this->assertNotNull($session);

        // 2) Schüler tritt bei (neuer Gast-Kontext)
        $student = $this->withSession([])->post('/join', [
            'join_token' => $game->join_token,
            'display_type' => 'emoji',
        ]);
        $student->assertRedirect(route('play'));

        // 3) Lehrer zeigt erste Frage
        $this->actingAs($teacher)->post("/sessions/{$session->id}/question", ['direction' => 'next']);
        $session->refresh();
        $this->assertSame($question->id, $session->current_question_id);
        $this->assertSame('active', $session->status);

        // 4) Auflösen broadcastet die Lösung
        $this->actingAs($teacher)->post("/sessions/{$session->id}/reveal")->assertRedirect();

        // 5) Spiel beenden
        $this->actingAs($teacher)->post("/sessions/{$session->id}/end")->assertRedirect();
        $this->assertSame('ended', $session->fresh()->status);

        // Broadcasts geprüft
        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'state');
        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'reveal');
        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'ended');

        // Datensparsamkeit: keine Schüler-/Antwort-/Fokus-Tabellen
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('participants'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('responses'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('focus_events'));
    }
}
