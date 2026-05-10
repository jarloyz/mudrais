<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guild_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guild_id')->constrained('guilds')->cascadeOnDelete();
            $table->string('discord_user_id');
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();

            $table->index('guild_id');
            $table->index('discord_user_id');
            $table->unique(['guild_id', 'discord_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_profiles');
    }
};
