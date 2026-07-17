<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\User;
use App\Support\QuestionDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Legt ein Demo-Lehrerkonto und ein Beispiel-Quiz an.
     */
    public function run(): void
    {
        $teacher = User::firstOrCreate(
            ['email' => 'lehrer@schulquizz.test'],
            ['name' => 'Demo-Lehrkraft', 'password' => Hash::make('passwort')],
        );

        if ($teacher->games()->exists()) {
            return;
        }

        $game = new Game();
        $game->user_id = $teacher->id;
        $game->title = 'Demo-Quiz: Allgemeinwissen';
        $game->description = 'Ein Beispiel mit mehreren Fragetypen.';
        $game->status = 'ready';
        $game->save();

        // Choice
        $q1 = QuestionDefaults::create($game, 'choice', 0);
        $q1->setTranslations('prompt_text', ['de' => 'Welche Farbe hat der Himmel bei klarem Wetter?']);
        $q1->save();
        $labels = ['Blau', 'Grün', 'Rot', 'Gelb'];
        foreach ($q1->answerOptions()->orderBy('position')->get() as $i => $o) {
            $o->setTranslations('text', ['de' => $labels[$i]]);
            $o->is_correct = $i === 0;
            $o->save();
        }

        // Schätzfrage
        $q2 = QuestionDefaults::create($game, 'estimate', 1);
        $q2->setTranslations('prompt_text', ['de' => 'Wie viele Bundesländer hat Deutschland?']);
        $q2->config = ['correct' => 16, 'tolerance' => 0, 'unit' => ''];
        $q2->save();

        // Umfrage-Slider (ohne Wertung)
        $q3 = QuestionDefaults::create($game, 'poll_slider', 2);
        $q3->setTranslations('prompt_text', ['de' => 'Wie fandest du das Quiz bisher?']);
        $q3->config = [
            'left' => ['mode' => 'text', 'text' => ['de' => 'Langweilig']],
            'right' => ['mode' => 'text', 'text' => ['de' => 'Super']],
            'live_results' => true,
        ];
        $q3->save();

        // Wortwolke
        $q4 = QuestionDefaults::create($game, 'word_cloud', 3);
        $q4->setTranslations('prompt_text', ['de' => 'Nenne bis zu 3 Wörter, die dir zu „Schule" einfallen.']);
        $q4->config = ['max_words' => 3];
        $q4->save();
    }
}
