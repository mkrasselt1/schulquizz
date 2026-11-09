<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Services\SessionService;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_live_sessions_are_ended_and_old_ones_deleted(): void
    {
        $game = $this->game();
        $service = app(SessionService::class);

        $stale = $service->start($game, ['join_method' => 'qr']);
        GameSession::whereKey($stale->id)->update(['updated_at' => Carbon::now()->subHours(9)]);

        $old = $service->start($this->game(), ['join_method' => 'qr']);
        $service->end($old);
        GameSession::whereKey($old->id)->update(['updated_at' => Carbon::now()->subDays(40)]);

        $fresh = $service->start($this->game(), ['join_method' => 'qr']);

        $this->artisan('schulquizz:cleanup')->assertSuccessful();

        $this->assertSame('ended', $stale->fresh()->status);
        $this->assertNull(GameSession::find($old->id));
        $this->assertSame('lobby', $fresh->fresh()->status);
    }

    public function test_unused_images_and_deleted_game_folders_are_removed(): void
    {
        Storage::fake('public');
        $game = $this->game();
        $q = QuestionDefaults::create($game, 'choice', 0);

        $disk = Storage::disk('public');
        $disk->put("game-images/{$game->id}/used.png", 'x');
        $disk->put("game-images/{$game->id}/orphan.png", 'x');
        $disk->put("game-images/{$game->id}/fresh.png", 'x');
        $disk->put('game-images/99999/gone.png', 'x');
        $q->update(['prompt_image_path' => "game-images/{$game->id}/used.png"]);

        $old = Carbon::now()->subDays(2)->getTimestamp();
        foreach (['used', 'orphan'] as $name) {
            touch($disk->path("game-images/{$game->id}/{$name}.png"), $old);
        }

        $this->artisan('schulquizz:cleanup')->assertSuccessful();

        $disk->assertExists("game-images/{$game->id}/used.png");
        $disk->assertExists("game-images/{$game->id}/fresh.png"); // evtl. noch nicht gespeichert
        $disk->assertMissing("game-images/{$game->id}/orphan.png");
        $disk->assertMissing('game-images/99999');
    }

    public function test_deleting_a_game_removes_its_images(): void
    {
        Storage::fake('public');
        $game = $this->game();
        Storage::disk('public')->put("game-images/{$game->id}/a.png", 'x');

        $game->delete();

        Storage::disk('public')->assertMissing("game-images/{$game->id}");
    }

    public function test_a_whole_class_behind_one_ip_can_join(): void
    {
        $game = $this->game();
        app(SessionService::class)->start($game, ['join_method' => 'qr']);

        for ($i = 0; $i < 30; $i++) {
            $this->flushSession();
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji'])
                ->assertRedirect(route('play'));
        }
    }

    public function test_state_contains_server_time(): void
    {
        $session = app(SessionService::class)->start($this->game(), ['join_method' => 'qr']);

        $state = app(SessionService::class)->stateFor($session);

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}/', $state['server_time']);
    }

    private function game(): Game
    {
        $game = new Game();
        $game->user_id = User::factory()->create()->id;
        $game->title = 'Quiz';
        $game->save();

        return $game->refresh();
    }
}
