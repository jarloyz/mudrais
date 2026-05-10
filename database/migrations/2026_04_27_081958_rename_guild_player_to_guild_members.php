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
        Schema::rename('guild_player', 'guild_members');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('guild_members', 'guild_player');
    }
};
