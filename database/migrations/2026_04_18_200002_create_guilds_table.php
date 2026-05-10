<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guilds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('discord_guild_id')->unique();
            $table->foreignUuid('archetype_id')->constrained('archetypes');
            $table->string('stripe_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->tinyInteger('plan_tier')->default(1);
            $table->integer('profile_quota')->default(50);
            $table->timestamps();

            $table->index('discord_guild_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guilds');
    }
};
