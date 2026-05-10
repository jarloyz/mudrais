<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite preserves the original FK (user_id → users.id) after a renameColumn,
 * because SQLite does not support DROP CONSTRAINT.  This migration recreates
 * player_agent_configs from scratch so that player_id correctly references
 * players.id on every driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // Copy existing rows before dropping the table.
        $rows = DB::table('player_agent_configs')->get();

        Schema::drop('player_agent_configs');

        Schema::create('player_agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->unique()->constrained('players')->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        foreach ($rows as $row) {
            DB::table('player_agent_configs')->insert((array) $row);
        }
    }

    public function down(): void
    {
        // Not reversible in isolation; rely on the parent migration's down().
    }
};
