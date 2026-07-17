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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            // choice | cloze | free_text | draw | sort | poll_slider | estimate | word_cloud
            $table->string('type');

            $table->string('prompt_mode')->default('text');    // text | image
            $table->json('prompt_text')->nullable();           // translatable
            $table->string('prompt_image_path')->nullable();

            $table->unsignedInteger('time_limit')->nullable(); // Sekunden, null = kein Limit
            $table->unsignedInteger('points')->default(1000);
            $table->boolean('scored')->default(true);
            $table->boolean('reveal_after')->nullable();       // null = Spiel-Default erben

            $table->json('config')->nullable();                // typ-spezifisch
            $table->timestamps();

            $table->index(['game_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
