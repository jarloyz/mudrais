<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatar_profile', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('avatar_id');                          // UUID
            $table->uuid('player_archetype_profile_id'); // UUID
            $table->timestamps();

            $table->foreign('avatar_id')
                  ->references('id')->on('avatars')->onDelete('cascade');
            $table->foreign('player_archetype_profile_id')
                  ->references('id')->on('player_archetype_profiles')->onDelete('cascade');

            $table->unique(['avatar_id', 'player_archetype_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_profile');
    }
};
