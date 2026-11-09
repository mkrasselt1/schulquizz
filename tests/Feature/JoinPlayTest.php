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

class JoinPlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_join_page_renders(): void
    {
        $this->get('/join')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Play/Join')->where('mode', 'pin'));
    }

    public function test_qr_page_shows_waiting_when_no_active_session(): void
    {
        [$game] = $this->gameWithSession(false);

        $this->get('/j/'.$game->join_token)
            ->assertInertia(fn ($p) => $p->component('Play/Join')->where('active', false));
    }

    public function test_student_can_join_with_name_via_pin(): void
    {
        [$game, $session] = $this->gameWithSession(true, 'pin');

        $this->post('/join', [
            'pin' => $session->pin,
            'display_type' => 'name',
            'name' => 'Lena',
        ])->assertRedirect(route('play'));

        $this->assertSame('Lena', session('participant')['label']);
        $this->assertSame('name', session('participant')['kind']);
    }

    public function test_blocked_name_is_rejected(): void
    {
        [$game, $session] = $this->gameWithSession(true, 'pin');

        $this->post('/join', [
            'pin' => $session->pin,
            'display_type' => 'name',
            'name' => 'Hitler',
        ])->assertSessionHasErrors('name');

        $this->assertNull(session('participant'));
    }

    public function test_anonymous_join_assigns_emoji(): void
    {
        [$game, $session] = $this->gameWithSession(true, 'qr');

        $this->post('/join', [
            'join_token' => $game->join_token,
            'display_type' => 'emoji',
        ])->assertRedirect(route('play'));

        $participant = session('participant');
        $this->assertSame('emoji', $participant['kind']);
        $this->assertNotEmpty($participant['label']);
    }

    public function test_play_requires_participant_session(): void
    {
        $this->get('/play')->assertRedirect(route('join.pin'));
    }

    public function test_answer_broadcasts_to_teacher_without_persisting(): void
    {
        Event::fake([SessionEvent::class]);
        [$game, $session] = $this->gameWithSession(true, 'qr');
        $question = QuestionDefaults::create($game, 'choice', 0);
        app(SessionService::class)->showQuestion($session, $question);

        // Beitreten (setzt Teilnehmer-Session).
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);

        $this->postJson('/play/answer', [
            'question_id' => $question->id,
            'answer' => [$question->answerOptions()->first()->id],
            'response_ms' => 1200,
        ])->assertOk();

        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'answer'
            && $e->scope === 'teacher'
            && $e->payload['question_id'] === $question->id);

        // Keine Antworttabelle existiert – Datensparsamkeit.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('responses'));
    }

    public function test_focus_event_broadcasts_to_teacher(): void
    {
        Event::fake([SessionEvent::class]);
        [$game] = $this->gameWithSession(true, 'qr');
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);

        $this->postJson('/play/focus', ['type' => 'tab_hidden'])->assertOk();

        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'focus'
            && $e->scope === 'teacher'
            && $e->payload['type'] === 'tab_hidden');
    }

    /**
     * @return array{0: Game, 1: GameSession|null}
     */
    private function gameWithSession(bool $active, string $joinMethod = 'qr'): array
    {
        $user = User::factory()->create();
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'Live-Quiz';
        $game->save();
        $game->refresh();

        $session = null;
        if ($active) {
            $session = app(SessionService::class)->start($game, ['join_method' => $joinMethod]);
        }

        return [$game, $session];
    }
}
