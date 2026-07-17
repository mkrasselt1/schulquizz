<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use App\Services\GamePorter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_requires_authentication(): void
    {
        $this->get('/library')->assertRedirect('/login');
    }

    public function test_teacher_can_create_a_game_and_gets_a_join_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/games', ['title' => 'Bruchrechnen', 'description' => 'Klasse 6'])
            ->assertRedirect();

        $game = Game::first();
        $this->assertNotNull($game);
        $this->assertSame($user->id, $game->user_id);
        $this->assertSame('Bruchrechnen', $game->title);
        $this->assertNotEmpty($game->join_token);
        $this->assertIsArray($game->settings);
    }

    public function test_library_only_lists_own_games(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->makeGame($other, 'Fremdes Spiel');
        $mine = $this->makeGame($me, 'Mein Spiel');

        $this->actingAs($me)
            ->get('/library')
            ->assertInertia(fn ($page) => $page
                ->component('Library/Index')
                ->has('games', 1)
                ->where('games.0.id', $mine->id));
    }

    public function test_duplicate_creates_independent_copy_with_new_token(): void
    {
        $user = User::factory()->create();
        $game = $this->makeGame($user, 'Original');

        $this->actingAs($user)->post("/games/{$game->id}/duplicate")->assertRedirect();

        $this->assertSame(2, Game::count());
        $copy = Game::latest('id')->first();
        $this->assertNotSame($game->join_token, $copy->join_token);
        $this->assertStringContainsString('(Kopie)', $copy->title);
    }

    public function test_export_then_import_produces_an_independent_copy(): void
    {
        $author = User::factory()->create();
        $game = $this->makeGame($author, 'Teilbares Quiz');

        // Export
        $payload = app(GamePorter::class)->export($game);
        $this->assertSame(GamePorter::FORMAT, $payload['format']);

        // Import beim anderen Lehrer
        $importer = User::factory()->create();
        $json = json_encode($payload);
        $file = UploadedFile::fake()->createWithContent('teilbares-quiz.quiz.json', $json);

        $this->actingAs($importer)
            ->post('/games/import', ['file' => $file])
            ->assertRedirect();

        $copy = Game::where('user_id', $importer->id)->first();
        $this->assertNotNull($copy);
        $this->assertSame('Teilbares Quiz', $copy->title);
        $this->assertNotSame($game->join_token, $copy->join_token);
    }

    public function test_teacher_cannot_edit_another_users_game(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $game = $this->makeGame($owner, 'Geschützt');

        $this->actingAs($intruder)
            ->put("/games/{$game->id}", ['title' => 'Gehackt'])
            ->assertForbidden();
    }

    public function test_teacher_can_delete_own_game(): void
    {
        $user = User::factory()->create();
        $game = $this->makeGame($user, 'Weg damit');

        $this->actingAs($user)->delete("/games/{$game->id}")->assertRedirect('/library');
        $this->assertSame(0, Game::count());
    }

    private function makeGame(User $user, string $title): Game
    {
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = $title;
        $game->save();

        return $game->refresh();
    }
}
