<?php

namespace Tests\Feature;

use App\Events\SessionEvent;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Services\SessionService;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SessionControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_session_creates_lobby_with_pin_when_pin_mode(): void
    {
        $user = User::factory()->create();
        $game = $this->game($user);

        $this->actingAs($user)->post("/games/{$game->id}/session", [
            'join_method' => 'pin',
            'advance_mode' => 'teacher',
        ])->assertRedirect();

        $session = GameSession::first();
        $this->assertSame('lobby', $session->status);
        $this->assertNotNull($session->pin);
        $this->assertNotEmpty($session->session_key);
    }

    public function test_qr_mode_has_no_pin(): void
    {
        $user = User::factory()->create();
        $game = $this->game($user);
        $session = app(SessionService::class)->start($game, ['join_method' => 'qr']);

        $this->assertNull($session->pin);
        $this->assertSame('qr', $session->join_method);
    }

    public function test_only_one_active_session_per_game(): void
    {
        $user = User::factory()->create();
        $game = $this->game($user);
        $service = app(SessionService::class);

        $first = $service->start($game, ['join_method' => 'qr']);
        $second = $service->start($game, ['join_method' => 'qr']);

        $this->assertSame('ended', $first->fresh()->status);
        $this->assertSame(1, GameSession::whereIn('status', ['lobby', 'active', 'paused'])->count());
        $this->assertSame($second->id, GameSession::activeForGame($game)->id);
    }

    public function test_showing_question_sets_deadline_and_broadcasts_state(): void
    {
        Event::fake([SessionEvent::class]);
        $user = User::factory()->create();
        $game = $this->game($user);
        $question = QuestionDefaults::create($game, 'choice', 0);
        $question->update(['time_limit' => 20]);
        $session = app(SessionService::class)->start($game, ['join_method' => 'qr']);

        app(SessionService::class)->showQuestion($session, $question);

        $session->refresh();
        $this->assertSame('active', $session->status);
        $this->assertSame($question->id, $session->current_question_id);
        $this->assertNotNull($session->question_deadline);
        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'state');
    }

    public function test_reveal_broadcasts_correct_answer_but_state_hides_it(): void
    {
        Event::fake([SessionEvent::class]);
        $user = User::factory()->create();
        $game = $this->game($user);
        $question = QuestionDefaults::create($game, 'choice', 0);
        $correctId = $question->answerOptions()->where('is_correct', true)->first()->id;
        $session = app(SessionService::class)->start($game, ['join_method' => 'qr']);
        app(SessionService::class)->showQuestion($session, $question);

        // Der schülersichere State enthält keine is_correct-Info.
        $state = app(SessionService::class)->stateFor($session->fresh());
        $this->assertArrayNotHasKey('is_correct', $state['question']['options'][0]);

        app(SessionService::class)->reveal($session->fresh());
        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'reveal'
            && in_array($correctId, $e->payload['reveal']['correct_option_ids']));
    }

    public function test_control_page_renders_with_full_questions_for_owner(): void
    {
        $user = User::factory()->create();
        $game = $this->game($user);
        QuestionDefaults::create($game, 'choice', 0);
        $session = app(SessionService::class)->start($game, ['join_method' => 'qr']);

        $this->actingAs($user)->get("/sessions/{$session->id}/control")
            ->assertInertia(fn ($p) => $p->component('Sessions/Control')
                ->has('questions', 1)
                ->has('questions.0.options')); // Lösungen für lokales Scoring vorhanden
    }

    public function test_teacher_cannot_control_another_users_session(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $session = app(SessionService::class)->start($this->game($owner), ['join_method' => 'qr']);

        $this->actingAs($intruder)->post("/sessions/{$session->id}/reveal")->assertForbidden();
    }

    public function test_board_relay_broadcasts_to_board_scope(): void
    {
        Event::fake([SessionEvent::class]);
        $user = User::factory()->create();
        $session = app(SessionService::class)->start($this->game($user), ['join_method' => 'qr']);

        $this->actingAs($user)->postJson("/sessions/{$session->id}/board", [
            'event' => 'podium',
            'payload' => ['entries' => [['label' => '🦊', 'score' => 100]]],
        ])->assertOk();

        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'board_podium'
            && $e->scope === 'board');
    }

    public function test_public_board_page_renders_without_auth(): void
    {
        $user = User::factory()->create();
        $session = app(SessionService::class)->start($this->game($user), ['join_method' => 'qr']);

        $this->get('/board/'.$session->session_key)
            ->assertInertia(fn ($p) => $p->component('Board')->where('sessionKey', $session->session_key));
    }

    private function game(User $user): Game
    {
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'Session-Test';
        $game->save();

        return $game->refresh();
    }
}
