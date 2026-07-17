<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Question;
use App\Support\QuestionDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    /**
     * Neue Frage eines Typs anlegen (mit sinnvollen Defaults für Config/Antworten).
     */
    public function store(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('update', $game);

        $data = $request->validate([
            'type' => ['required', Rule::in(Question::TYPES)],
        ]);

        DB::transaction(function () use ($game, $data) {
            $position = (int) $game->questions()->max('position') + 1;
            QuestionDefaults::create($game, $data['type'], $position);
        });

        return back()->with('status', 'question-created');
    }

    /**
     * Frage samt Antworten speichern (voller Payload, transaktionaler Sync).
     */
    public function update(Request $request, Game $game, Question $question): RedirectResponse
    {
        $this->authorize('update', $game);
        abort_unless($question->game_id === $game->id, 404);

        $data = $request->validate([
            'prompt_mode' => ['required', 'in:text,image'],
            'prompt_text' => ['nullable', 'array'],
            'prompt_image_path' => ['nullable', 'string'],
            'time_limit' => ['nullable', 'integer', 'min:3', 'max:3600'],
            'points' => ['required', 'integer', 'min:0', 'max:100000'],
            'scored' => ['required', 'boolean'],
            'reveal_after' => ['nullable', 'boolean'],
            'config' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.mode' => ['required_with:options', 'in:text,image'],
            'options.*.text' => ['nullable', 'array'],
            'options.*.image_path' => ['nullable', 'string'],
            'options.*.is_correct' => ['boolean'],
        ]);

        DB::transaction(function () use ($question, $data) {
            $question->fill([
                'prompt_mode' => $data['prompt_mode'],
                'prompt_image_path' => $data['prompt_image_path'] ?? null,
                'time_limit' => $data['time_limit'] ?? null,
                'points' => $data['points'],
                'scored' => $data['scored'],
                'reveal_after' => $data['reveal_after'] ?? null,
                'config' => $data['config'] ?? [],
            ]);
            if (array_key_exists('prompt_text', $data)) {
                $question->setTranslations('prompt_text', $data['prompt_text'] ?? []);
            }
            $question->save();

            $this->syncOptions($question, $data['options'] ?? []);
        });

        return back()->with('status', 'question-saved');
    }

    public function destroy(Game $game, Question $question): RedirectResponse
    {
        $this->authorize('update', $game);
        abort_unless($question->game_id === $game->id, 404);

        $question->delete();

        return back()->with('status', 'question-deleted');
    }

    /**
     * Reihenfolge der Fragen setzen (Drag & Drop).
     */
    public function reorder(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('update', $game);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($game, $data) {
            foreach ($data['ids'] as $index => $id) {
                $game->questions()->where('id', $id)->update(['position' => $index]);
            }
        });

        return back()->with('status', 'questions-reordered');
    }

    /**
     * Antworten synchronisieren: vorhandene aktualisieren, neue anlegen, entfernte löschen.
     */
    private function syncOptions(Question $question, array $options): void
    {
        $keptIds = [];

        foreach (array_values($options) as $index => $opt) {
            $attributes = [
                'position' => $index,
                'mode' => $opt['mode'] ?? 'text',
                'image_path' => $opt['image_path'] ?? null,
                'is_correct' => (bool) ($opt['is_correct'] ?? false),
            ];

            $model = ! empty($opt['id'])
                ? $question->answerOptions()->find($opt['id'])
                : null;

            if ($model) {
                $model->fill($attributes);
            } else {
                $model = $question->answerOptions()->make($attributes);
            }
            $model->setTranslations('text', $opt['text'] ?? []);
            $model->save();

            $keptIds[] = $model->id;
        }

        $question->answerOptions()->whereNotIn('id', $keptIds ?: [0])->delete();
    }
}
