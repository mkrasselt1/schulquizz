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
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Übersetzbare Inhalte als JSON (locale => value); UI-Editor folgt später.
            $table->json('title');
            $table->json('description')->nullable();

            // Stabiler Beitritts-Token für den festen QR-Code (in Materialien einbindbar).
            $table->string('join_token', 32)->unique();

            $table->string('base_locale', 5)->default('de');
            $table->json('settings')->nullable();       // Spiel-Defaults (Timer, Weiterschalten, Reveal …)
            $table->string('status')->default('draft'); // draft | ready

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
