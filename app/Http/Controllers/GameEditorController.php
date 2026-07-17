<?php

namespace App\Http\Controllers;

use App\Http\Resources\QuestionResource;
use App\Models\Game;
use App\Models\Question;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class GameEditorController extends Controller
{
    public function edit(Game $game): Response
    {
        $this->authorize('update', $game);

        $game->load(['questions.answerOptions']);

        return Inertia::render('Games/Editor', [
            'game' => [
                'id' => $game->id,
                'title' => $game->title,
                'title_translations' => $game->getTranslations('title'),
                'description' => $game->description,
                'base_locale' => $game->base_locale,
                'status' => $game->status,
                'settings' => $game->settings,
                'join_token' => $game->join_token,
                'join_url' => url('/j/'.$game->join_token),
            ],
            'questions' => QuestionResource::collection($game->questions)->resolve(),
            'questionTypes' => Question::TYPES,
            'mediaBase' => Storage::disk('public')->url(''),
        ]);
    }
}
