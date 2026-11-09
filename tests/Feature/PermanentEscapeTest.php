<?php

namespace Tests\Feature;

use App\Events\SessionEvent;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Services\SessionService;
use App\Support\CompletionCode;
use App\Support\EscapeRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PermanentEscapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_always_open_opens_and_closes_a_permanent_session(): void
    {
        [$user, $game] = $this->escapeGame();

        $this->actingAs($user)->put("/games/{$game->id}/escape", ['escape' => [...$game->escape, 'always_open' => true]])
            ->assertSessionHasNoErrors();
        $session = GameSession::activeForGame($game);
        $this->assertNotNull($session);
        $this->assertTrue($session->settings['permanent']);
        $this->assertSame('active', $session->status);

        // Beitritt über den festen QR-Link klappt jederzeit
        $this->get('/j/'.$game->join_token)->assertInertia(fn ($p) => $p->where('active', true));

        $this->actingAs($user)->put("/games/{$game->id}/escape", ['escape' => [...$game->fresh()->escape, 'always_open' => false]]);
        $this->assertSame('ended', $session->fresh()->status);
    }

    public function test_cannot_open_permanently_while_a_class_game_runs(): void
    {
        [$user, $game] = $this->escapeGame();
        $class = app(SessionService::class)->start($game, ['join_method' => 'pin']);

        $this->actingAs($user)->put("/games/{$game->id}/escape", ['escape' => [...$game->escape, 'always_open' => true]])
            ->assertSessionHasErrors('always_open');
        $this->assertSame('lobby', $class->fresh()->status);
        $this->assertFalse($game->fresh()->escape['always_open']);
    }

    public function test_cleanup_keeps_permanent_rooms_open(): void
    {
        [, $game] = $this->escapeGame();
        $session = app(SessionService::class)->openPermanent($game);
        GameSession::whereKey($session->id)->update(['updated_at' => Carbon::now()->subDays(3)]);

        $this->artisan('schulquizz:cleanup')->assertSuccessful();

        $this->assertSame('active', $session->fresh()->status);
    }

    public function test_each_student_gets_own_time_and_rejoin_keeps_it(): void
    {
        Event::fake([SessionEvent::class]);
        [, $game] = $this->escapeGame(timeLimit: 10);
        app(SessionService::class)->openPermanent($game);

        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'name', 'name' => 'Lena']);
        $first = $this->getJson('/play/escape')->assertOk()->json();
        $this->assertTrue($first['permanent']);
        $this->assertNotNull($first['personal_deadline']);

        // Neu beitreten (gleiches Gerät) → gleiche Startzeit, kein Zeit-Reset
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'name', 'name' => 'Lena']);
        $this->assertSame($first['started_at'], $this->getJson('/play/escape')->json('started_at'));

        // Nach Ablauf der eigenen Zeit: zu spät
        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_exit', 'answer' => '42'])
            ->assertStatus(409)->assertJson(['reason' => 'time_up']);
        Carbon::setTestNow();
    }

    public function test_escaping_gives_a_verifiable_unique_completion_code(): void
    {
        Event::fake([SessionEvent::class]);
        [$user, $game] = $this->escapeGame();
        app(SessionService::class)->openPermanent($game);

        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'name', 'name' => 'Lena Müller']);
        $this->getJson('/play/escape')->assertOk();
        $completion = $this->postJson('/play/escape/check', ['hotspot_id' => 'h_exit', 'answer' => '42'])
            ->assertJson(['result' => 'escaped'])->json('completion');

        $this->assertMatchesRegularExpression('/^ESC(-[0-9A-Z]{4}){7}$/', $completion['code']);
        $this->assertSame('Lena Müller', $completion['name']);

        // Lehrkraft fügt die komplette Chat-Nachricht ein
        $message = "Ich habe den Escape-Room „Labor“ geschafft! 🎉\nName: Lena Müller\nZeit: 0:01\nAbschluss-Code: {$completion['code']}\n(Prüfen: x)";
        $this->actingAs($user)->postJson('/escape/verify', ['text' => $message])
            ->assertJson(['valid' => true, 'name' => 'Lena Müller', 'game_title' => 'Labor']);

        // Groß/klein, Leerzeichen und abgetippte O/0 sind egal
        $sloppy = strtolower(str_replace('-', ' ', $completion['code']));
        $this->actingAs($user)->postJson('/escape/verify', ['code' => $sloppy, 'name' => 'lena müller'])
            ->assertJson(['valid' => true]);

        // Weitergeben an jemand anderen funktioniert nicht
        $this->actingAs($user)->postJson('/escape/verify', ['code' => $completion['code'], 'name' => 'Max'])
            ->assertJson(['valid' => false, 'reason' => 'signature']);

        // Fremde Lehrkraft sieht nichts
        $this->actingAs(User::factory()->create())->postJson('/escape/verify', ['text' => $message])
            ->assertJson(['valid' => false, 'reason' => 'foreign_game']);
    }

    public function test_codes_are_unique_and_tampering_is_detected(): void
    {
        $a = CompletionCode::issue(7, 'Lena', 65_000);
        $b = CompletionCode::issue(7, 'Lena', 65_000);
        $this->assertNotSame($a, $b);

        $ok = CompletionCode::verify($a, 'Lena');
        $this->assertTrue($ok['valid']);
        $this->assertSame(7, $ok['game_id']);
        $this->assertSame(65, $ok['elapsed_seconds']);

        // Ein Zeichen ändern (z. B. Zeit "verbessern") → ungültig
        $chars = str_split($a);
        $chars[8] = $chars[8] === 'A' ? 'B' : 'A';
        $this->assertFalse(CompletionCode::verify(implode('', $chars), 'Lena')['valid']);
        $this->assertFalse(CompletionCode::verify('ESC-KAPUTT', 'Lena')['valid']);
    }

    public function test_class_mode_escape_also_gives_a_code(): void
    {
        Event::fake([SessionEvent::class]);
        [, $game] = $this->escapeGame();
        $session = app(SessionService::class)->start($game, ['join_method' => 'qr']);
        app(SessionService::class)->startEscape($session);
        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'name', 'name' => 'Tom']);

        $this->postJson('/play/escape/check', ['hotspot_id' => 'h_exit', 'answer' => '42'])
            ->assertJsonPath('completion.name', 'Tom');
    }

    public function test_ending_the_permanent_session_switches_always_open_off(): void
    {
        [$user, $game] = $this->escapeGame();
        $session = app(SessionService::class)->openPermanent($game);

        $this->actingAs($user)->post("/sessions/{$session->id}/end");

        $this->assertFalse($game->fresh()->escape['always_open']);
    }

    /**
     * @return array{0: User, 1: Game}
     */
    private function escapeGame(?int $timeLimit = 30): array
    {
        $user = User::factory()->create();
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'Labor';
        $game->mode = 'escape';
        $game->save();
        $game->escape = EscapeRoom::sanitize([
            'time_limit' => $timeLimit,
            'rooms' => [['id' => 'r_1', 'hotspots' => [['id' => 'h_exit', 'kind' => 'lock', 'code' => '42']]]],
        ], $game);
        $game->save();

        return [$user, $game->refresh()];
    }
}
