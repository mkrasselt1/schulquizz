<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\GamePorter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    /**
     * Bibliothek der Lehrkraft – geräteübergreifend synchron (serverseitig pro Konto).
     */
    public function index(): Response
    {
        $games = Auth::user()->games()
            ->latest('updated_at')
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'title' => $game->title,
                'description' => $game->description,
                'status' => $game->status,
                'mode' => $game->mode,
                'join_token' => $game->join_token,
                'updated_at' => $game->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('Library/Index', [
            'games' => $games,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_locale' => ['nullable', 'in:de,en'],
            'mode' => ['nullable', 'in:'.implode(',', Game::MODES)],
        ]);

        $game = new Game();
        $game->mode = $data['mode'] ?? 'quiz';
        $game->user_id = Auth::id();
        $game->base_locale = $data['base_locale'] ?? app()->getLocale();
        $game->title = $data['title'];
        $game->description = $data['description'] ?? null;
        $game->save();

        return redirect()->route('games.edit', $game)
            ->with('status', 'game-created');
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('update', $game);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:draft,ready'],
        ]);

        $game->title = $data['title'];
        $game->description = $data['description'] ?? null;
        if (isset($data['status'])) {
            $game->status = $data['status'];
        }
        $game->save();

        return back()->with('status', 'game-updated');
    }

    public function destroy(Game $game): RedirectResponse
    {
        $this->authorize('delete', $game);

        $game->delete();

        return redirect()->route('library')->with('status', 'game-deleted');
    }

    /**
     * Spiel duplizieren – erzeugt eine eigenständige Kopie mit neuem Join-Token.
     */
    public function duplicate(Game $game, GamePorter $porter): RedirectResponse
    {
        $this->authorize('view', $game);

        // Über Export/Import kopieren: so werden Fragen, Antworten, Bilder und die
        // Escape-Room-Verweise (Fragen-IDs) sauber mitkopiert.
        $data = $porter->export($game);
        $titles = (array) ($data['game']['title'] ?? []);
        foreach ($titles as $loc => $val) {
            $titles[$loc] = $val.' (Kopie)';
        }
        $data['game']['title'] = $titles ?: ['de' => 'Kopie'];

        $copy = $porter->import($data, Auth::user());

        return redirect()->route('games.edit', $copy)->with('status', 'game-duplicated');
    }
}
