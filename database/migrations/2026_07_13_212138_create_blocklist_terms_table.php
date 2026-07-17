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
        Schema::create('blocklist_terms', function (Blueprint $table) {
            $table->id();
            // user_id null = globale Basisliste; sonst pro Lehrkraft erweitert.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('term');
            $table->string('match_type')->default('substring'); // substring | exact
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocklist_terms');
    }
};
