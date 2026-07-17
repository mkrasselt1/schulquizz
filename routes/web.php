<?php

use App\Http\Controllers\BlocklistController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\GameEditorController;
use App\Http\Controllers\GameExportController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Landing', [
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', fn () => redirect()->route('library'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// --- Schüler-Beitritt & Spiel (kein Konto, keine Auth) ---
Route::get('/join', [JoinController::class, 'viaPin'])->name('join.pin');
Route::get('/j/{token}', [JoinController::class, 'viaToken'])->name('join.token');
Route::get('/j/{token}/status', [JoinController::class, 'status'])->name('join.status');
Route::post('/join', [JoinController::class, 'join'])->middleware('throttle:20,1')->name('join.submit');
Route::post('/leave', [JoinController::class, 'leave'])->name('join.leave');
Route::get('/play', [PlayController::class, 'show'])->name('play');
Route::post('/play/answer', [PlayController::class, 'answer'])->middleware('throttle:120,1')->name('play.answer');
Route::post('/play/focus', [PlayController::class, 'focus'])->middleware('throttle:120,1')->name('play.focus');

// Tafel/Beamer (öffentlich, unratbarer Key)
Route::get('/board/{key}', [BoardController::class, 'show'])->name('board');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // --- Bibliothek & Spiele ---
    Route::get('/library', [LibraryController::class, 'index'])->name('library');
    Route::post('/games', [LibraryController::class, 'store'])->name('games.store');
    Route::put('/games/{game}', [LibraryController::class, 'update'])->name('games.update');
    Route::delete('/games/{game}', [LibraryController::class, 'destroy'])->name('games.destroy');
    Route::post('/games/{game}/duplicate', [LibraryController::class, 'duplicate'])->name('games.duplicate');

    // Export / Import (Teilen & Sichern)
    Route::get('/games/{game}/export', [GameExportController::class, 'export'])->name('games.export');
    Route::post('/games/import', [GameExportController::class, 'import'])->name('games.import');

    // Editor
    Route::get('/games/{game}/edit', [GameEditorController::class, 'edit'])->name('games.edit');

    // Fragen
    Route::post('/games/{game}/questions', [QuestionController::class, 'store'])->name('questions.store');
    Route::put('/games/{game}/questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
    Route::delete('/games/{game}/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');
    Route::post('/games/{game}/questions/reorder', [QuestionController::class, 'reorder'])->name('questions.reorder');

    // Medien-Upload (lokal, keine externen Dienste)
    Route::post('/games/{game}/media', [MediaController::class, 'store'])->name('media.store');

    // Moderation (Wort-Sperrliste, pro Lehrkraft)
    Route::get('/moderation', [BlocklistController::class, 'index'])->name('moderation');
    Route::post('/moderation', [BlocklistController::class, 'store'])->name('moderation.store');
    Route::delete('/moderation/{term}', [BlocklistController::class, 'destroy'])->name('moderation.destroy');

    // --- Live-Session (Lehrer-Steuerung) ---
    Route::post('/games/{game}/session', [SessionController::class, 'store'])->name('sessions.store');
    Route::get('/sessions/{session}/control', [SessionController::class, 'control'])->name('sessions.control');
    Route::post('/sessions/{session}/pause', [SessionController::class, 'pause'])->name('sessions.pause');
    Route::post('/sessions/{session}/resume', [SessionController::class, 'resume'])->name('sessions.resume');
    Route::post('/sessions/{session}/end', [SessionController::class, 'end'])->name('sessions.end');
    Route::post('/sessions/{session}/question', [SessionController::class, 'question'])->name('sessions.question');
    Route::post('/sessions/{session}/phase', [SessionController::class, 'phase'])->name('sessions.phase');
    Route::post('/sessions/{session}/timer', [SessionController::class, 'timer'])->name('sessions.timer');
    Route::post('/sessions/{session}/reveal', [SessionController::class, 'reveal'])->name('sessions.reveal');
    Route::post('/sessions/{session}/board', [SessionController::class, 'board'])->name('sessions.board');
});

require __DIR__.'/auth.php';
