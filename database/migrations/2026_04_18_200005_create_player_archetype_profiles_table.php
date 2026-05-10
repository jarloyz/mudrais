<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_archetype_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('discord_user_id');
            $table->foreignUuid('archetype_id')->constrained('archetypes');
            $table->json('positive_prefs');
            $table->json('red_lines')->nullable();
            $table->json('metadata')->nullable();
            $table->text('optimized_text')->nullable();
            $table->uuid('qdrant_id')->nullable();
            $table->timestamps();

            $table->index('discord_user_id');
            $table->index('archetype_id');
            $table->unique(['discord_user_id', 'archetype_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_archetype_profiles');
    }
};
