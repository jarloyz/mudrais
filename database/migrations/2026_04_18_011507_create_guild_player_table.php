<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guild_player', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->uuid('guild_id'); // Discord snowflake ID
            $table->timestamps();
            $table->unique(['player_id', 'guild_id']);
            $table->index('guild_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_player');
    }
};
