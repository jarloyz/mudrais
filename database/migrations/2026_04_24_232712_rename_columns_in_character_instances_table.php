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
        Schema::table('character_instances', function (Blueprint $table) {
            $table->renameColumn('scene_id', 'activity_id');
            $table->renameColumn('character_id', 'avatar_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('character_instances', function (Blueprint $table) {
            $table->renameColumn('activity_id', 'scene_id');
            $table->renameColumn('avatar_id', 'character_id');
        });
    }
};
