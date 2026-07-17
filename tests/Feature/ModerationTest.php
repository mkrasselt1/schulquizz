<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use App\Services\ModerationService;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_list_blocks_offensive_terms(): void
    {
        $mod = app(ModerationService::class);
        $this->assertFalse($mod->isAllowed('Hitler'));
        $this->assertFalse($mod->isAllowed('n-a-z-i')); // Verschleierung
        $this->assertTrue($mod->isAllowed('Lena'));
    }

    public function test_teacher_can_add_term_and_it_blocks_student_name(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->post('/moderation', [
            'term' => 'blabla',
            'match_type' => 'substring',
        ])->assertRedirect();

        $this->assertDatabaseHas('blocklist_terms', ['user_id' => $teacher->id, 'term' => 'blabla']);

        // Session dieses Lehrers → Name mit dem Begriff wird abgelehnt.
        $game = new Game();
        $game->user_id = $teacher->id;
        $game->title = 'Q';
        $game->save();
        $session = app(SessionService::class)->start($game->refresh(), ['join_method' => 'pin']);

        $this->post('/join', [
            'pin' => $session->pin,
            'display_type' => 'name',
            'name' => 'super blabla',
        ])->assertSessionHasErrors('name');
    }

    public function test_teacher_term_does_not_affect_other_teacher(): void
    {
        $mod = app(ModerationService::class);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $a->blocklistTerms()->create(['term' => 'apfel', 'match_type' => 'substring']);

        $this->assertFalse($mod->isAllowed('apfel', $a->id));
        $this->assertTrue($mod->isAllowed('apfel', $b->id));
    }

    public function test_moderation_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/moderation')
            ->assertInertia(fn ($p) => $p->component('Moderation/Index')->has('baseCount'));
    }

    public function test_landing_page_renders(): void
    {
        $this->get('/')->assertInertia(fn ($p) => $p->component('Landing'));
    }
}
