<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guild_bots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guild_id')->constrained('guilds')->cascadeOnDelete();
            $table->foreignUuid('discord_bot_id')->constrained('discord_bots')->cascadeOnDelete();
            $table->timestamp('installed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['guild_id', 'discord_bot_id']);
            $table->index('guild_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_bots');
    }
};
