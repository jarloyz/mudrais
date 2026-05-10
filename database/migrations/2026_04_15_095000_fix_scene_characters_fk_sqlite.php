<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite preserves the original FK (controlled_by_user_id → users.id) after
 * renameColumn because SQLite does not support DROP CONSTRAINT.
 * This migration recreates scene_characters from scratch so that
 * controlled_by_player_id correctly references players.id on every driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $rows = DB::table('scene_characters')->get();

        Schema::drop('scene_characters');

        Schema::create('scene_characters', function (Blueprint $table): void {
            $table->uuid('scene_id');
            $table->uuid('character_id');
            $table->string('role', 50)->nullable();
            $table->uuid('controlled_by_player_id')->nullable();
            $table->string('scene_role', 50)->default('npc');
            $table->integer('initiative_score')->default(0);
            $table->boolean('has_acted_this_round')->default(false);
            $table->timestamps();

            $table->primary(['scene_id', 'character_id']);
            $table->foreign('scene_id')->references('id')->on('scenes')->cascadeOnDelete();
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
            $table->foreign('controlled_by_player_id')->references('id')->on('players')->nullOnDelete();
        });

        foreach ($rows as $row) {
            DB::table('scene_characters')->insert((array) $row);
        }
    }

    public function down(): void
    {
        // Not reversible in isolation; rely on the parent migration's down().
    }
};
