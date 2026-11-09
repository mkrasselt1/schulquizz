<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\GamePorter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GameExportController extends Controller
{
    public function __construct(private readonly GamePorter $porter)
    {
    }

    /**
     * Spiel als portable .quiz.json-Datei herunterladen (inkl. Fragen & eingebetteter Bilder).
     */
    public function export(Game $game): StreamedResponse
    {
        $this->authorize('view', $game);

        $payload = $this->porter->export($game);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        $slug = \Illuminate\Support\Str::slug($game->title ?: 'quiz') ?: 'quiz';
        $filename = $slug . '.quiz.json';

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * .quiz.json importieren – erzeugt beim aktuellen Lehrer eine eigenständige Kopie
     * mit neuem Join-Token.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20 MB
        ]);

        $raw = file_get_contents($request->file('file')->getRealPath());
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return back()->withErrors(['file' => 'Die Datei ist keine gültige .quiz.json.']);
        }

        try {
            $game = $this->porter->import($data, Auth::user());
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['file' => 'Import fehlgeschlagen: Die Datei ist beschädigt oder hat ein unbekanntes Format.']);
        }

        return redirect()->route('games.edit', $game)->with('status', 'game-imported');
    }
}
