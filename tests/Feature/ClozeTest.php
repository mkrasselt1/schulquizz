<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use App\Support\PublicQuestion;
use App\Support\QuestionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClozeTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloze_default_has_example_gap(): void
    {
        $game = $this->game();
        $q = QuestionDefaults::create($game, 'cloze', 0);

        $this->assertStringContainsString('[[Berlin]]', $q->prompt_text);
        $gaps = PublicQuestion::clozeGaps($q->prompt_text);
        $this->assertSame(['Berlin'], $gaps[0]['accepted']);
    }

    public function test_player_view_strips_answers_but_keeps_structure(): void
    {
        $game = $this->game();
        $q = QuestionDefaults::create($game, 'cloze', 0);
        $q->setTranslations('prompt_text', ['de' => 'Wasser gefriert bei [[0|null]] Grad.']);
        $q->save();

        $public = PublicQuestion::forPlayers($q->refresh());

        // Rohtext mit Lösungen darf NICHT ausgeliefert werden.
        $this->assertSame([], $public['prompt_text']);
        $json = json_encode($public);
        $this->assertStringNotContainsString('[[', $json);
        $this->assertStringNotContainsString('null]]', $json);

        // Segmente: Text + genau eine Lücke.
        $segments = $public['config']['cloze']['de'];
        $gapCount = count(array_filter($segments, fn ($s) => isset($s['gap'])));
        $this->assertSame(1, $gapCount);
    }

    public function test_reveal_returns_accepted_per_gap(): void
    {
        $game = $this->game();
        $q = QuestionDefaults::create($game, 'cloze', 0);
        $q->setTranslations('prompt_text', ['de' => 'A [[eins]] B [[zwei|2]].']);
        $q->save();

        $reveal = PublicQuestion::reveal($q->refresh());
        $this->assertSame(['eins'], $reveal['gaps'][0]['accepted']);
        $this->assertSame(['zwei', '2'], $reveal['gaps'][1]['accepted']);
    }

    private function game(): Game
    {
        $user = User::create(['name' => 'T', 'email' => 't@t.de', 'password' => bcrypt('x')]);
        $game = new Game();
        $game->user_id = $user->id;
        $game->title = 'T';
        $game->save();

        return $game->refresh();
    }
}
