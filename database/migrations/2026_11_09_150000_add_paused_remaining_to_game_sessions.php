<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pause hält die Zeit an: Restzeit beim Pausieren merken, beim Fortsetzen neu ansetzen.
     */
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('paused_remaining_ms')->nullable()->after('question_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropColumn('paused_remaining_ms');
        });
    }
};
