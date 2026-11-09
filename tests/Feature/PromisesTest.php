<?php

namespace Tests\Feature;

use App\Events\SessionEvent;
use App\Models\Game;
use App\Models\User;
use App\Services\SessionService;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Was wir in PRIVACY.md/README versprechen – als Tests festgehalten.
 */
class PromisesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sessions_live_in_cookies_not_in_the_database_by_default(): void
    {
        // Tests selbst nutzen den array-Treiber – daher die ausgelieferten Defaults prüfen.
        $this->assertStringContainsString("env('SESSION_DRIVER', 'cookie')", file_get_contents(base_path('config/session.php')));
        $this->assertStringContainsString('SESSION_DRIVER=cookie', file_get_contents(base_path('Dockerfile')));
        $this->assertStringContainsString('SESSION_DRIVER=cookie', file_get_contents(base_path('.env.docker.example')));
        $this->assertStringNotContainsString('SESSION_DRIVER=database', file_get_contents(base_path('.env.example')));
    }

    public function test_health_check_loads_nothing_external(): void
    {
        $body = $this->get('/up')->assertOk()->getContent();
        $this->assertSame('OK', $body);
    }

    public function test_pages_send_security_headers_and_csp_without_external_hosts(): void
    {
        $res = $this->get('/')->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('Referrer-Policy', 'same-origin');
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $csp);
    }

    public function test_blocked_words_in_text_answers_are_masked_before_reaching_the_teacher(): void
    {
        Event::fake([SessionEvent::class]);
        $teacher = User::factory()->create();
        $teacher->blocklistTerms()->create(['term' => 'apfel', 'match_type' => 'substring']);
        $game = new Game();
        $game->user_id = $teacher->id;
        $game->title = 'Freitext';
        $game->save();
        $question = QuestionDefaults::create($game->refresh(), 'free_text', 0);
        $session = app(SessionService::class)->start($game, ['join_method' => 'qr']);
        app(SessionService::class)->showQuestion($session, $question);

        $this->post('/join', ['join_token' => $game->join_token, 'display_type' => 'emoji']);
        $this->postJson('/play/answer', ['question_id' => $question->id, 'answer' => 'Ein Apfel'])->assertOk();
        $this->postJson('/play/answer', ['question_id' => $question->id, 'answer' => ['Birne', 'Arschloch']])->assertOk();

        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'answer' && $e->payload['answer'] === '***');
        Event::assertDispatched(SessionEvent::class, fn ($e) => $e->event === 'answer' && $e->payload['answer'] === ['Birne', '***']);
    }

    public function test_cleanup_removes_expired_database_sessions_if_that_driver_is_used(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            ['id' => 'old', 'payload' => 'x', 'last_activity' => now()->subHours(5)->timestamp, 'ip_address' => '1.2.3.4'],
            ['id' => 'new', 'payload' => 'x', 'last_activity' => now()->timestamp, 'ip_address' => '1.2.3.4'],
        ]);

        $this->artisan('schulquizz:cleanup')->assertSuccessful();

        $this->assertSame(['new'], DB::table('sessions')->pluck('id')->all());
    }
}
