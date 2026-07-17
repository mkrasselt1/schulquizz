<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Minimaler Laufzeit-Anker: KEINE personenbezogenen Schülerdaten.
        // Dient nur dem Beitritts-Routing und dem aktuellen Fortschritt.
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Unguessbarer Schlüssel für Channel-Namen & Board-URL.
            $table->string('session_key', 40)->unique();
            $table->string('pin', 6)->nullable();       // nur im PIN-Modus
            $table->string('join_method')->default('pin'); // pin | qr

            $table->string('status')->default('lobby'); // lobby | active | paused | ended
            $table->string('advance_mode')->default('teacher'); // teacher | free
            $table->foreignId('current_question_id')->nullable();
            $table->string('current_phase')->nullable(); // prompt | answers | reveal
            $table->json('settings')->nullable();        // Snapshot beim Start (überschreibbar)
            $table->timestamp('question_started_at')->nullable();
            $table->timestamp('question_deadline')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            $table->index(['game_id', 'status']);
            $table->index('pin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};
