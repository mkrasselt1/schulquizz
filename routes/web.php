<?php

use App\Http\Controllers\BlocklistController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\EscapeController;
use App\Http\Controllers\EscapeVerifyController;
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
        'canRegister' => Route::has('register') && config('schulquizz.registration_enabled'),
    ]);
});

Route::get('/dashboard', fn () => redirect()->route('library'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// --- Schüler-Beitritt & Spiel (kein Konto, keine Auth) ---
Route::get('/join', [JoinController::class, 'viaPin'])->name('join.pin');
Route::get('/j/{token}', [JoinController::class, 'viaToken'])->name('join.token');
Route::get('/j/{token}/status', [JoinController::class, 'status'])->name('join.status');
Route::post('/join', [JoinController::class, 'join'])->middleware('throttle:join')->name('join.submit');
Route::post('/leave', [JoinController::class, 'leave'])->name('join.leave');
Route::get('/play', [PlayController::class, 'show'])->name('play');
Route::get('/play/state', [PlayController::class, 'state'])->middleware('throttle:play')->name('play.state');
Route::post('/play/escape/resync', [PlayController::class, 'escapeResync'])->middleware('throttle:play')->name('play.escape.resync');
Route::post('/play/answer', [PlayController::class, 'answer'])->middleware('throttle:play')->name('play.answer');
Route::get('/play/escape', [PlayController::class, 'escape'])->name('play.escape');
Route::post('/play/escape/check', [PlayController::class, 'escapeCheck'])->middleware('throttle:escape')->name('play.escape.check');
Route::post('/play/focus', [PlayController::class, 'focus'])->middleware('throttle:play')->name('play.focus');

// Tafel/Beamer (öffentlich, unratbarer Key)
Route::get('/board/{key}', [BoardController::class, 'show'])->name('board');
Route::get('/board/{key}/state', [BoardController::class, 'state'])->middleware('throttle:120,1')->name('board.state');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::delete('/profile/apps/{client}', [ProfileController::class, 'revokeApp'])->name('profile.apps.revoke');

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
    Route::put('/games/{game}/escape', [EscapeController::class, 'update'])->name('escape.update');
    Route::post('/games/{game}/questions/reorder', [QuestionController::class, 'reorder'])->name('questions.reorder');

    // Medien-Upload (lokal, keine externen Dienste)
    Route::post('/games/{game}/media', [MediaController::class, 'store'])->name('media.store');

    // Escape-Room: Abschluss-Codes prüfen
    Route::get('/escape/verify', [EscapeVerifyController::class, 'show'])->name('escape.verify');
    Route::post('/escape/verify', [EscapeVerifyController::class, 'check'])->middleware('throttle:60,1')->name('escape.verify.check');

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
    Route::post('/sessions/{session}/escape/start', [SessionController::class, 'escapeStart'])->name('sessions.escape.start');
    Route::post('/sessions/{session}/phase', [SessionController::class, 'phase'])->name('sessions.phase');
    Route::post('/sessions/{session}/timer', [SessionController::class, 'timer'])->name('sessions.timer');
    Route::post('/sessions/{session}/reveal', [SessionController::class, 'reveal'])->name('sessions.reveal');
    Route::post('/sessions/{session}/resync', [SessionController::class, 'resync'])->name('sessions.resync');
    Route::post('/sessions/{session}/board', [SessionController::class, 'board'])->name('sessions.board');
});

require __DIR__.'/auth.php';
