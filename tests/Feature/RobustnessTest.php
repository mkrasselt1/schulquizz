<?php

namespace Tests\Feature;

use App\Events\SessionEvent;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Services\GamePorter;
use App\Services\SessionService;
use App\Support\EscapeRoom;
use App\Support\PublicQuestion;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Regressionstests aus dem Robustheits-Review (unbeaufsichtigter Schulbetrieb).
 */
class RobustnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_ended_session_cannot_be_revived(): void
    {
        [$user, $game] = $this->game();
        $session = app(SessionService::class)->start($game, ['join_method' => 'pin']);
        app(SessionService::class)->end($session);

        $this->actingAs($user)->post("/sessions/{$session->id}/question", ['direction' => 'next'])->assertStatus(409);
        $this->actingAs($user)->post("/sessions/{$session->id}/resume")->assertStatus(409);
        $this->assertSame('ended', $session->fresh()->status);
    }

    public function test_starting_again_goes_to_running_session_instead_of_kicking_class(): void
    {
        [$user, $game] = $this->game();
        $running = app(SessionService::class)->start($game, ['join_method' => 'qr']);

        $this->actingAs($user)->post("/games/{$game->id}/session", ['join_method' => 'qr', 'advance_mode' => 'teacher'])
            ->assertRedirect(route('sessions.control', $running));
        $this->assertSame('lobby', $running->fresh()->status);
    }

    public function test_identity_is_only_reused_within_the_same_session(): void
    {
        [, $game] = $this->game();
        $first = app(SessionService::class)->start($game, ['join_method' => 'qr']);

        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);
        $token = session('participant.token');
        $emoji = session('participant.label');

        // Gleiches Gerät, gleiches Spiel → gleiche Identität
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);
        $this->assertSame($token, session('participant.token'));
        $this->assertSame($emoji, session('participant.label'));

        // Neues Spiel (z. B. nächste Stunde am selben Tablet) → neue Identität,
        // auch wenn der Browser alte Daten mitschickt.
        app(SessionService::class)->end($first);
        app(SessionService::class)->start($game->fresh(), ['join_method' => 'qr']);
        $this->post('/join', [
            'join_token' => $game->join_token, 'display_type' => 'emoji',
            'participant_token' => $token, 'reconnect_emoji' => 'BÖSES WORT',
        ]);
        $this->assertNotSame($token, session('participant.token'));
        $this->assertNotSame('BÖSES WORT', session('participant.label'));
    }

    public function test_answers_only_accepted_for_current_open_question(): void
    {
        Event::fake([SessionEvent::class]);
        [, $game] = $this->game();
        $q1 = QuestionDefaults::create($game, 'choice', 0);
        $q2 = QuestionDefaults::create($game, 'choice', 1);
        $service = app(SessionService::class);
        $session = $service->start($game, ['join_method' => 'qr']);
        $service->showQuestion($session, $q2);
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);

        $this->postJson('/play/answer', ['question_id' => $q1->id, 'answer' => [1]])->assertStatus(409);
        $this->postJson('/play/answer', ['question_id' => $q2->id, 'answer' => [1]])->assertOk();

        $service->reveal($session->fresh());
        $this->postJson('/play/answer', ['question_id' => $q2->id, 'answer' => [1]])->assertStatus(409);
    }

    public function test_pause_freezes_and_resume_restores_remaining_time(): void
    {
        [, $game] = $this->game();
        $q = QuestionDefaults::create($game, 'choice', 0);
        $q->update(['time_limit' => 60]);
        $service = app(SessionService::class);
        $session = $service->start($game, ['join_method' => 'qr']);
        $service->showQuestion($session, $q);

        $service->pause($session->fresh());
        $paused = $session->fresh();
        $this->assertNull($paused->question_deadline);
        $this->assertGreaterThan(55_000, $paused->paused_remaining_ms);

        Carbon::setTestNow(Carbon::now()->addMinutes(10)); // lange Pause
        $service->resume($paused);
        $resumed = $session->fresh();
        $this->assertTrue($resumed->question_deadline->isFuture());
        $this->assertGreaterThan(55, Carbon::now()->diffInSeconds($resumed->question_deadline));
        Carbon::setTestNow();
    }

    public function test_escape_receipts_can_be_resent_but_not_forged(): void
    {
        Event::fake([SessionEvent::class]);
        [, $game] = $this->game('escape');
        $game->escape = EscapeRoom::sanitize(['rooms' => [[
            'id' => 'r_1', 'hotspots' => [['id' => 'h_lock', 'kind' => 'lock', 'code' => '42']],
        ]]], $game);
        $game->save();
        $session = app(SessionService::class)->start($game->fresh(), ['join_method' => 'qr']);
        app(SessionService::class)->startEscape($session);
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);

        $receipt = $this->postJson('/play/escape/check', ['hotspot_id' => 'h_lock', 'answer' => '42'])
            ->assertJson(['result' => 'escaped'])->json('receipt');

        $this->postJson('/play/escape/resync', ['receipts' => [$receipt]])->assertJson(['accepted' => 1]);

        $forged = [...$receipt, 'elapsed_ms' => 1];
        $this->postJson('/play/escape/resync', ['receipts' => [$forged]])->assertJson(['accepted' => 0]);
    }

    public function test_deleting_a_puzzle_question_clears_escape_reference(): void
    {
        [$user, $game] = $this->game('escape');
        $q = QuestionDefaults::create($game, 'choice', 0);
        $game->escape = EscapeRoom::sanitize(['rooms' => [[
            'id' => 'r_1', 'hotspots' => [['id' => 'h_p', 'kind' => 'puzzle', 'question_id' => $q->id]],
        ]]], $game);
        $game->save();

        $this->actingAs($user)->delete("/games/{$game->id}/questions/{$q->id}")->assertRedirect();

        $this->assertNull(EscapeRoom::findHotspot($game->fresh(), 'h_p')['hotspot']['question_id']);
    }

    public function test_requires_on_clue_hotspots_is_dropped(): void
    {
        [, $game] = $this->game('escape');
        $escape = EscapeRoom::sanitize(['rooms' => [[
            'id' => 'r_1', 'hotspots' => [
                ['id' => 'h_clue', 'kind' => 'clue'],
                ['id' => 'h_lock', 'kind' => 'lock', 'code' => '1', 'requires' => ['h_clue']],
            ],
        ]]], $game);

        $this->assertSame([], $escape['rooms'][0]['hotspots'][1]['requires']);
    }

    public function test_escape_games_only_allow_gradable_puzzle_types(): void
    {
        [$user, $game] = $this->game('escape');

        $this->actingAs($user)->post("/games/{$game->id}/questions", ['type' => 'draw'])->assertSessionHasErrors('type');
        $this->actingAs($user)->post("/games/{$game->id}/questions", ['type' => 'cloze'])->assertSessionHasNoErrors();
    }

    public function test_foreign_image_paths_are_rejected(): void
    {
        [$user, $game] = $this->game();
        $q = QuestionDefaults::create($game, 'choice', 0);

        $this->actingAs($user)->put("/games/{$game->id}/questions/{$q->id}", [
            'prompt_mode' => 'image', 'points' => 1000, 'scored' => true,
            'prompt_image_path' => '../../.env',
        ])->assertSessionHasErrors('prompt_image_path');
    }

    public function test_malformed_import_does_not_break_the_library(): void
    {
        [$user] = $this->game();

        $game = app(GamePorter::class)->import([
            'format' => GamePorter::FORMAT,
            'game' => [
                'title' => ['de' => ['kaputt' => 1], 'en' => 'Broken'],
                'settings' => 'x',
                'questions' => [
                    ['type' => 'gibtsnicht'],
                    'kein-array',
                    ['type' => 'choice', 'prompt_text' => ['de' => ['x']], 'options' => ['x', ['text' => ['de' => 'A']]]],
                ],
            ],
        ], $user);

        $this->assertSame('Broken', $game->title);
        $this->assertIsArray($game->settings);
        $this->assertSame(1, $game->questions()->count());
        $this->actingAs($user)->get('/library')->assertOk();
    }

    public function test_new_questions_use_the_games_base_language(): void
    {
        [, $game] = $this->game();
        $game->base_locale = 'en';
        $game->save();

        $q = QuestionDefaults::create($game, 'choice', 0);

        $this->assertArrayHasKey('en', $q->getTranslations('prompt_text'));
        $this->assertArrayNotHasKey('de', $q->getTranslations('prompt_text'));
        $this->assertSame('Antwort A', $q->answerOptions()->first()->getTranslations('text')['en']);
    }

    public function test_sort_never_starts_in_the_correct_order(): void
    {
        [, $game] = $this->game();
        for ($i = 0; $i < 20; $i++) {
            $q = QuestionDefaults::create($game, 'sort', $i)->load('answerOptions');
            $q->answerOptions()->orderByDesc('position')->first()->delete(); // 2 Elemente
            $q->load('answerOptions');
            $correct = $q->answerOptions->sortBy('position')->pluck('id')->all();
            $shown = array_column(PublicQuestion::forPlayers($q)['options'], 'id');
            $this->assertNotSame($correct, $shown);
        }
    }

    public function test_registration_code_is_enforced_when_set(): void
    {
        config(['schulquizz.registration_code' => 'lehrerzimmer']);
        $data = ['name' => 'L', 'email' => 'l@schule.de', 'password' => 'geheim1234', 'password_confirmation' => 'geheim1234'];

        $this->post('/register', [...$data, 'registration_code' => 'falsch'])->assertSessionHasErrors('registration_code');
        $this->assertGuest();

        $this->post('/register', [...$data, 'registration_code' => 'lehrerzimmer'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_registration_can_be_disabled(): void
    {
        config(['schulquizz.registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Game}
     */
    private function game(string $mode = 'quiz'): array
    {
        $user = User::factory()->create();
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'Spiel';
        $game->mode = $mode;
        $game->save();

        return [$user, $game->refresh()];
    }
}
