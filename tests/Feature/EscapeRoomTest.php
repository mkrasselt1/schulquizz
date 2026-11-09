<?php

namespace Tests\Feature;

use App\Events\SessionEvent;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\Question;
use App\Models\User;
use App\Services\GamePorter;
use App\Services\SessionService;
use App\Support\AnswerGrader;
use App\Support\EscapeRoom;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EscapeRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_create_escape_game(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/games', ['title' => 'Labor', 'mode' => 'escape'])->assertRedirect();

        $this->assertSame('escape', Game::first()->mode);
    }

    public function test_saving_escape_sanitizes_structure(): void
    {
        [$user, $game, $question] = $this->escapeGame();
        $foreign = QuestionDefaults::create($this->escapeGame()[1], 'choice', 0);

        $this->actingAs($user)->put("/games/{$game->id}/escape", [
            'escape' => [
                'time_limit' => 20,
                'rooms' => [[
                    'id' => 'r_1',
                    'title' => ['de' => 'Raum'],
                    'image_path' => '../../etc/passwd',
                    'hotspots' => [
                        ['id' => 'h_a', 'kind' => 'puzzle', 'x' => 90, 'y' => -5, 'w' => 50, 'h' => 10, 'question_id' => $question->id],
                        ['id' => 'h_b', 'kind' => 'puzzle', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'question_id' => $foreign->id],
                        ['id' => 'h_c', 'kind' => 'lock', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'code' => ' 1234 ', 'requires' => ['h_a', 'h_zzz', 'h_c']],
                    ],
                ]],
            ],
        ])->assertRedirect();

        $escape = $game->fresh()->escape;
        $hs = $escape['rooms'][0]['hotspots'];
        $this->assertNull($escape['rooms'][0]['image_path']);
        $this->assertSame(90.0, (float) $hs[0]['x']);
        $this->assertSame(0.0, (float) $hs[0]['y']);
        $this->assertSame(10.0, (float) $hs[0]['w']); // auf Bildrand begrenzt
        $this->assertSame($question->id, $hs[0]['question_id']);
        $this->assertNull($hs[1]['question_id']); // Frage eines anderen Spiels
        $this->assertSame('1234', $hs[2]['code']);
        $this->assertSame(['h_a'], $hs[2]['requires']);
    }

    public function test_saving_keeps_all_room_and_game_fields(): void
    {
        Storage::fake('public');
        [$user, $game] = $this->escapeGame();

        $path = $this->actingAs($user)
            ->postJson("/games/{$game->id}/media", ['image' => UploadedFile::fake()->image('raum.jpg')])
            ->json('path');

        $this->actingAs($user)->put("/games/{$game->id}/escape", [
            'escape' => [
                'intro' => ['de' => 'Ihr seid eingeschlossen'],
                'outro' => ['de' => 'Geschafft'],
                'time_limit' => 25,
                'show_hotspots' => false,
                'rooms' => [[
                    'id' => 'r_labor',
                    'title' => ['de' => 'Labor'],
                    'image_path' => $path,
                    'hotspots' => [],
                ]],
            ],
        ])->assertRedirect();

        $escape = $game->fresh()->escape;
        $this->assertSame(['de' => 'Ihr seid eingeschlossen'], $escape['intro']);
        $this->assertSame(['de' => 'Geschafft'], $escape['outro']);
        $this->assertSame(25, $escape['time_limit']);
        $this->assertFalse($escape['show_hotspots']);
        $this->assertSame('r_labor', $escape['rooms'][0]['id']);
        $this->assertSame(['de' => 'Labor'], $escape['rooms'][0]['title']);
        $this->assertSame($path, $escape['rooms'][0]['image_path']);
    }

    public function test_other_teacher_cannot_save_escape(): void
    {
        [, $game] = $this->escapeGame();

        $this->actingAs(User::factory()->create())
            ->put("/games/{$game->id}/escape", ['escape' => ['rooms' => [[]]]])
            ->assertForbidden();
    }

    public function test_player_view_hides_codes_rewards_and_solutions(): void
    {
        [, $game, $question] = $this->escapeGame();
        $this->configure($game, $question);

        $json = json_encode(EscapeRoom::forPlayers($game->fresh()));

        $this->assertStringNotContainsString('4711', $json);
        $this->assertStringNotContainsString('GEHEIMNIS', $json);
        $this->assertStringNotContainsString('is_correct', $json);
        $this->assertStringContainsString('"code_length":4', $json);
    }

    public function test_state_contains_escape_only_after_start_and_never_in_broadcast(): void
    {
        Event::fake([SessionEvent::class]);
        [, $game, $question] = $this->escapeGame();
        $this->configure($game, $question);
        $service = app(SessionService::class);
        $session = $service->start($game->fresh(), ['join_method' => 'qr']);

        $this->assertNull($service->stateFor($session)['escape']);

        $service->startEscape($session);
        $session->refresh();

        $this->assertSame('active', $session->status);
        $this->assertNotNull($session->question_deadline);
        $this->assertNotNull($service->stateFor($session)['escape']);
        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'state'
            && $e->payload['escape'] === null
            && $e->payload['escape_room_count'] === 2);
    }

    public function test_correct_puzzle_returns_reward_and_reports_to_teacher(): void
    {
        Event::fake([SessionEvent::class]);
        [, $game, $question] = $this->escapeGame();
        $this->configure($game, $question);
        $this->joinStarted($game);

        $correctId = $question->answerOptions()->where('is_correct', true)->first()->id;
        $wrongId = $question->answerOptions()->where('is_correct', false)->first()->id;

        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_puzzle', 'answer' => [$wrongId]])
            ->assertOk()->assertJson(['correct' => false, 'reward' => null]);

        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_puzzle', 'answer' => [$correctId]])
            ->assertOk()->assertJson(['correct' => true, 'reward' => ['de' => 'GEHEIMNIS'], 'result' => null]);

        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'escape_progress'
            && $e->scope === 'teacher'
            && $e->payload['hotspot_id'] === 'h_puzzle'
            && $e->payload['correct'] === true);
    }

    public function test_locks_lead_to_next_room_and_finally_out(): void
    {
        Event::fake([SessionEvent::class]);
        [, $game, $question] = $this->escapeGame();
        $this->configure($game, $question);
        $this->joinStarted($game);

        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_lock1', 'answer' => '0000'])
            ->assertJson(['correct' => false, 'result' => null]);
        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_lock1', 'answer' => '47 11'])
            ->assertJson(['correct' => true, 'result' => 'next_room']);
        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_exit', 'answer' => 'Freiheit'])
            ->assertJson(['correct' => true, 'result' => 'escaped']);

        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'escape_progress'
            && $e->payload['result'] === 'escaped');
    }

    public function test_checks_are_rejected_before_start_and_after_time_is_up(): void
    {
        [, $game, $question] = $this->escapeGame();
        $this->configure($game, $question);
        $session = app(SessionService::class)->start($game->fresh(), ['join_method' => 'qr']);
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);

        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_lock1', 'answer' => '4711'])->assertStatus(409);
        $this->getJson('/play/escape')->assertStatus(409);

        app(SessionService::class)->startEscape($session);
        $this->getJson('/play/escape')->assertOk()->assertJsonCount(2, 'rooms');

        $session->update(['question_deadline' => Carbon::now()->subMinute()]);
        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_lock1', 'answer' => '4711'])
            ->assertStatus(409)->assertJson(['reason' => 'time_up']);
    }

    public function test_clue_hotspots_cannot_be_checked(): void
    {
        [, $game, $question] = $this->escapeGame();
        $this->configure($game, $question);
        $this->joinStarted($game);

        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_clue', 'answer' => 'x'])->assertNotFound();
        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_unknown', 'answer' => 'x'])->assertNotFound();
    }

    public function test_export_import_and_duplicate_remap_puzzle_questions(): void
    {
        [$user, $game, $question] = $this->escapeGame();
        $this->configure($game, $question);

        $copy = app(GamePorter::class)->import(app(GamePorter::class)->export($game->fresh()), $user);
        $this->assertSame('escape', $copy->mode);
        $newQuestion = $copy->questions()->first();
        $this->assertNotSame($question->id, $newQuestion->id);
        $this->assertSame($newQuestion->id, EscapeRoom::findHotspot($copy, 'h_puzzle')['hotspot']['question_id']);

        $this->actingAs($user)->post("/games/{$game->id}/duplicate")->assertRedirect();
        $dup = Game::latest('id')->first();
        $this->assertSame(1, $dup->questions()->count());
        $this->assertSame(
            $dup->questions()->first()->id,
            EscapeRoom::findHotspot($dup, 'h_puzzle')['hotspot']['question_id'],
        );
    }

    public function test_grader_matches_frontend_rules(): void
    {
        $game = $this->escapeGame()[1];

        $free = QuestionDefaults::create($game, 'free_text', 1);
        $free->update(['config' => ['accepted' => ['Photosynthese'], 'threshold' => 0.8]]);
        $this->assertTrue(AnswerGrader::isCorrect($free, 'photosynthse'));
        $this->assertFalse(AnswerGrader::isCorrect($free, 'Atmung'));
        $this->assertFalse(AnswerGrader::isCorrect($free, ''));

        $cloze = QuestionDefaults::create($game, 'cloze', 2); // "… ist [[Berlin]]."
        $this->assertTrue(AnswerGrader::isCorrect($cloze, ['berlin']));
        $this->assertFalse(AnswerGrader::isCorrect($cloze, ['Paris']));

        $estimate = QuestionDefaults::create($game, 'estimate', 3);
        $estimate->update(['config' => ['correct' => 100, 'tolerance' => 5]]);
        $this->assertTrue(AnswerGrader::isCorrect($estimate, 104));
        $this->assertFalse(AnswerGrader::isCorrect($estimate, 110));
        $this->assertFalse(AnswerGrader::isCorrect($estimate, 'abc'));

        $sort = QuestionDefaults::create($game, 'sort', 4)->load('answerOptions');
        $ids = $sort->answerOptions->sortBy('position')->pluck('id')->all();
        $this->assertTrue(AnswerGrader::isCorrect($sort, $ids));
        $this->assertFalse(AnswerGrader::isCorrect($sort, array_reverse($ids)));
    }

    /**
     * @return array{0: User, 1: Game, 2: Question}
     */
    private function escapeGame(): array
    {
        $user = User::factory()->create();
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'Escape';
        $game->mode = 'escape';
        $game->save();
        $question = QuestionDefaults::create($game, 'choice', 0);

        return [$user, $game->refresh(), $question->load('answerOptions')];
    }

    private function configure(Game $game, Question $question): void
    {
        $game->escape = EscapeRoom::sanitize([
            'time_limit' => 30,
            'rooms' => [
                [
                    'id' => 'r_1',
                    'title' => ['de' => 'Labor'],
                    'hotspots' => [
                        ['id' => 'h_clue', 'kind' => 'clue', 'text' => ['de' => 'Schau unter den Tisch']],
                        ['id' => 'h_puzzle', 'kind' => 'puzzle', 'question_id' => $question->id, 'reward' => ['de' => 'GEHEIMNIS']],
                        ['id' => 'h_lock1', 'kind' => 'lock', 'code' => '4711', 'requires' => ['h_puzzle']],
                    ],
                ],
                [
                    'id' => 'r_2',
                    'title' => ['de' => 'Flur'],
                    'hotspots' => [
                        ['id' => 'h_exit', 'kind' => 'lock', 'code' => 'freiheit'],
                    ],
                ],
            ],
        ], $game);
        $game->save();
    }

    private function joinStarted(Game $game): GameSession
    {
        $session = app(SessionService::class)->start($game->fresh(), ['join_method' => 'qr']);
        app(SessionService::class)->startEscape($session);
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);

        return $session;
    }
}
