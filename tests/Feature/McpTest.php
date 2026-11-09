<?php

namespace Tests\Feature;

use App\Mcp\Servers\SchulquizzServer;
use App\Mcp\Tools\CreateGame;
use App\Mcp\Tools\CreatePuzzle;
use App\Mcp\Tools\DeletePuzzle;
use App\Mcp\Tools\GetGame;
use App\Mcp\Tools\ListGames;
use App\Mcp\Tools\SetEscapeRooms;
use App\Mcp\Tools\UpdatePuzzle;
use App\Models\Game;
use App\Models\Question;
use App\Models\User;
use App\Support\AnswerGrader;
use App\Support\EscapeRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_endpoint_requires_oauth_and_advertises_metadata(): void
    {
        $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');

        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()->assertJsonStructure(['resource', 'authorization_servers']);
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()->assertJsonStructure(['authorization_endpoint', 'token_endpoint', 'registration_endpoint']);
    }

    public function test_oauth_flow_registers_client_and_shows_german_consent_after_login(): void
    {
        // 1) KI-Client registriert sich selbst (kein Token-Kopieren)
        $clientId = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertSuccessful()->json('client_id');
        $this->assertNotEmpty($clientId);

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        // 2) Nicht angemeldet → Login
        $this->get('/oauth/authorize?'.$query)->assertRedirect(route('login'));

        // 3) Angemeldet → eigene, deutsche Zustimmungsseite
        $this->actingAs(User::factory()->create())
            ->get('/oauth/authorize?'.$query)
            ->assertOk()
            ->assertSee('Erlauben')
            ->assertSee('keine')
            ->assertDontSee('fonts.bunny.net');
    }

    public function test_login_from_consent_page_returns_there_with_full_reload(): void
    {
        $user = User::factory()->create(['password' => bcrypt('geheim1234')]);
        $this->withSession(['url.intended' => url('/oauth/authorize?client_id=abc')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'geheim1234'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/oauth/authorize?client_id=abc'));
    }

    public function test_full_escape_room_can_be_built_via_mcp(): void
    {
        $user = User::factory()->create();
        $mcp = fn () => SchulquizzServer::actingAs($user);

        $mcp()->tool(CreateGame::class, ['title' => 'Labor-Ausbruch', 'mode' => 'escape'])
            ->assertOk()->assertSee('Labor-Ausbruch');
        $game = Game::firstOrFail();
        $this->assertSame('escape', $game->mode);

        $mcp()->tool(CreatePuzzle::class, [
            'game_id' => $game->id, 'type' => 'choice', 'prompt' => 'Was ist H2O?',
            'options' => [['text' => 'Wasser', 'correct' => true], ['text' => 'Salz']],
        ])->assertOk()->assertSee('Wasser');
        $mcp()->tool(CreatePuzzle::class, [
            'game_id' => $game->id, 'type' => 'estimate', 'prompt' => 'Siedepunkt?', 'correct_value' => 100, 'tolerance' => 2, 'unit' => '°C',
        ])->assertOk();
        $mcp()->tool(CreatePuzzle::class, [
            'game_id' => $game->id, 'type' => 'cloze', 'prompt' => 'Das Element mit O ist [[Sauerstoff]].',
        ])->assertOk();
        $mcp()->tool(CreatePuzzle::class, [
            'game_id' => $game->id, 'type' => 'free_text', 'prompt' => 'Formel für Kochsalz?', 'accepted_answers' => ['NaCl'],
        ])->assertOk();

        [$choice, $estimate, $cloze, $free] = $game->questions()->with('answerOptions', 'game')->get()->all();
        $this->assertSame(2, $choice->answerOptions->count());
        $right = $choice->answerOptions->firstWhere('is_correct', true);
        $this->assertTrue(AnswerGrader::isCorrect($choice, [$right->id]));
        $this->assertTrue(AnswerGrader::isCorrect($estimate, 101));
        $this->assertTrue(AnswerGrader::isCorrect($cloze, ['sauerstoff']));
        $this->assertTrue(AnswerGrader::isCorrect($free, 'nacl'));

        $mcp()->tool(SetEscapeRooms::class, [
            'game_id' => $game->id,
            'intro' => 'Ihr seid eingeschlossen!',
            'time_limit_minutes' => 20,
            'rooms' => [[
                'id' => 'r_labor', 'title' => 'Labor',
                'hotspots' => [
                    ['id' => 'h_regal', 'kind' => 'puzzle', 'label' => 'Regal', 'x' => 10, 'y' => 20, 'w' => 15, 'h' => 20,
                        'question_id' => $choice->id, 'reward' => 'Ziffer: 4'],
                    ['id' => 'h_tuer', 'kind' => 'lock', 'label' => 'Tür', 'x' => 70, 'y' => 30, 'w' => 20, 'h' => 50,
                        'code' => '4', 'requires' => ['h_regal']],
                ],
            ]],
        ])->assertOk()->assertSee(['Labor', 'h_tuer', 'noch kein Hintergrundbild']);

        $escape = $game->fresh()->escape;
        $this->assertSame(['de' => 'Ihr seid eingeschlossen!'], $escape['intro']);
        $this->assertSame(20, $escape['time_limit']);
        $this->assertSame(['h_regal'], $escape['rooms'][0]['hotspots'][1]['requires']);

        // Antwort ändern
        $mcp()->tool(UpdatePuzzle::class, ['question_id' => $free->id, 'accepted_answers' => ['NaCl', 'Natriumchlorid']])
            ->assertOk()->assertSee('Natriumchlorid');
        $this->assertTrue(AnswerGrader::isCorrect($free->fresh(['game']), 'natriumchlorid'));

        // Gesamtansicht
        $mcp()->tool(GetGame::class, ['game_id' => $game->id])->assertOk()->assertSee(['Was ist H2O?', 'h_regal', 'Ziffer: 4']);
        $mcp()->tool(ListGames::class)->assertOk()->assertSee('Labor-Ausbruch');

        // Löschen entfernt den Verweis und warnt
        $mcp()->tool(DeletePuzzle::class, ['question_id' => $choice->id])->assertOk()->assertSee('kein gültiges Rätsel');
        $this->assertNull(EscapeRoom::findHotspot($game->fresh(), 'h_regal')['hotspot']['question_id']);
    }

    public function test_room_edit_keeps_uploaded_images_and_other_languages(): void
    {
        $user = User::factory()->create();
        $game = $this->escapeGame($user);
        $game->escape = EscapeRoom::sanitize(['rooms' => [[
            'id' => 'r_1', 'title' => ['de' => 'Alt', 'en' => 'Old'],
            'image_path' => "game-images/{$game->id}/bild.jpg",
            'hotspots' => [['id' => 'h_1', 'kind' => 'clue', 'text' => ['de' => 'alt', 'en' => 'old'],
                'image_path' => "game-images/{$game->id}/hinweis.jpg"]],
        ]]], $game);
        $game->save();

        SchulquizzServer::actingAs($user)->tool(SetEscapeRooms::class, [
            'game_id' => $game->id,
            'rooms' => [['id' => 'r_1', 'title' => 'Neu', 'hotspots' => [['id' => 'h_1', 'kind' => 'clue', 'text' => 'neu']]]],
        ])->assertOk();

        $room = $game->fresh()->escape['rooms'][0];
        $this->assertSame("game-images/{$game->id}/bild.jpg", $room['image_path']);
        $this->assertSame(['de' => 'Neu', 'en' => 'Old'], $room['title']);
        $this->assertSame("game-images/{$game->id}/hinweis.jpg", $room['hotspots'][0]['image_path']);
        $this->assertSame(['de' => 'neu', 'en' => 'old'], $room['hotspots'][0]['text']);
    }

    public function test_teachers_cannot_touch_other_teachers_games(): void
    {
        $owner = User::factory()->create();
        $game = $this->escapeGame($owner);
        $question = \App\Support\QuestionDefaults::create($game, 'choice', 0);
        $intruder = User::factory()->create();

        SchulquizzServer::actingAs($intruder)->tool(GetGame::class, ['game_id' => $game->id])->assertHasErrors();
        SchulquizzServer::actingAs($intruder)->tool(CreatePuzzle::class, ['game_id' => $game->id, 'type' => 'choice'])->assertHasErrors();
        SchulquizzServer::actingAs($intruder)->tool(UpdatePuzzle::class, ['question_id' => $question->id, 'prompt' => 'gehackt'])->assertHasErrors();
        SchulquizzServer::actingAs($intruder)->tool(DeletePuzzle::class, ['question_id' => $question->id])->assertHasErrors();
        SchulquizzServer::actingAs($intruder)->tool(ListGames::class)->assertDontSee('Escape');

        $this->assertNotNull(Question::find($question->id));
    }

    public function test_escape_games_reject_ungradable_puzzle_types(): void
    {
        $user = User::factory()->create();
        $game = $this->escapeGame($user);

        SchulquizzServer::actingAs($user)->tool(CreatePuzzle::class, ['game_id' => $game->id, 'type' => 'draw'])->assertHasErrors();
        $this->assertSame(0, $game->questions()->count());
    }

    private function escapeGame(User $user): Game
    {
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'Escape';
        $game->mode = 'escape';
        $game->save();

        return $game->refresh();
    }
}
