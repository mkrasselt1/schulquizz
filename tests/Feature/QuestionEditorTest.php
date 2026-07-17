<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Question;
use App\Models\User;
use App\Services\GamePorter;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_each_question_type_with_defaults(): void
    {
        $user = User::factory()->create();
        $game = $this->game($user);

        foreach (Question::TYPES as $type) {
            $this->actingAs($user)
                ->post("/games/{$game->id}/questions", ['type' => $type])
                ->assertRedirect();
        }

        $this->assertSame(count(Question::TYPES), $game->questions()->count());

        // Choice erhält Standard-Antworten, Umfragen sind ungewertet.
        $choice = $game->questions()->where('type', 'choice')->first();
        $this->assertSame(4, $choice->answerOptions()->count());
        $this->assertTrue($choice->scored);

        $poll = $game->questions()->where('type', 'poll_slider')->first();
        $this->assertFalse($poll->scored);
    }

    public function test_can_update_a_choice_question_and_sync_options(): void
    {
        $user = User::factory()->create();
        $game = $this->game($user);
        $question = QuestionDefaults::create($game, 'choice', 0);
        $keepId = $question->answerOptions()->first()->id;

        $this->actingAs($user)->put("/games/{$game->id}/questions/{$question->id}", [
            'prompt_mode' => 'text',
            'prompt_text' => ['de' => 'Was ist 2+2?'],
            'time_limit' => 30,
            'points' => 500,
            'scored' => true,
            'reveal_after' => true,
            'config' => ['multiple' => false],
            'options' => [
                ['id' => $keepId, 'mode' => 'text', 'text' => ['de' => '4'], 'is_correct' => true],
                ['mode' => 'text', 'text' => ['de' => '5'], 'is_correct' => false],
            ],
        ])->assertRedirect();

        $question->refresh();
        $this->assertSame('Was ist 2+2?', $question->prompt_text);
        $this->assertSame(500, $question->points);
        $this->assertSame(2, $question->answerOptions()->count());
        $this->assertSame('4', $question->answerOptions()->where('is_correct', true)->first()->text);
    }

    public function test_reorder_updates_positions(): void
    {
        $user = User::factory()->create();
        $game = $this->game($user);
        $a = QuestionDefaults::create($game, 'choice', 0);
        $b = QuestionDefaults::create($game, 'estimate', 1);

        $this->actingAs($user)->post("/games/{$game->id}/questions/reorder", [
            'ids' => [$b->id, $a->id],
        ])->assertRedirect();

        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    public function test_media_upload_returns_path(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $game = $this->game($user);

        $response = $this->actingAs($user)->post("/games/{$game->id}/media", [
            'image' => UploadedFile::fake()->image('bild.png', 100, 100),
        ]);

        $response->assertOk()->assertJsonStructure(['path', 'url']);
        Storage::disk('public')->assertExists($response->json('path'));
    }

    public function test_export_import_roundtrips_questions_and_images(): void
    {
        Storage::fake('public');
        $author = User::factory()->create();
        $game = $this->game($author);

        $q = QuestionDefaults::create($game, 'choice', 0);
        $q->prompt_image_path = UploadedFile::fake()->image('frage.png')->store("game-images/{$game->id}", 'public');
        $q->save();

        $payload = app(GamePorter::class)->export($game->fresh());
        $this->assertNotEmpty($payload['game']['questions']);
        $this->assertNotNull($payload['game']['questions'][0]['prompt_image']);

        $importer = User::factory()->create();
        $copy = app(GamePorter::class)->import($payload, $importer);

        $this->assertSame(1, $copy->questions()->count());
        $imported = $copy->questions()->first();
        $this->assertSame('choice', $imported->type);
        $this->assertNotNull($imported->prompt_image_path);
        Storage::disk('public')->assertExists($imported->prompt_image_path);
        $this->assertSame(4, $imported->answerOptions()->count());
    }

    private function game(User $user): Game
    {
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'Testquiz';
        $game->save();

        return $game->refresh();
    }
}
