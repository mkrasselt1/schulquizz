<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spielmodus (klassisches Live-Quiz oder Escape-Room) + Raum-/Hotspot-Struktur.
     * Die Rätsel selbst sind normale Fragen (questions) des Spiels; `escape` referenziert sie.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('mode')->default('quiz')->after('base_locale'); // quiz | escape
            $table->json('escape')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['mode', 'escape']);
        });
    }
};
