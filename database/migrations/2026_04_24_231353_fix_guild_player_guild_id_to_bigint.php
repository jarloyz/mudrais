<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        return;
        // 1. Añadir columna temporal bigint nullable
        Schema::table('guild_player', function (Blueprint $table) {
            $table->uuid('guild_id_new')->nullable();
        });

        // 2. Backfill: mapear discord_guild_id → guilds.id
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                UPDATE guild_player gp
                SET guild_id_new = g.id
                FROM guilds g
                WHERE g.discord_guild_id = gp.guild_id
            ');
        } else {
            // Compatibilidad para SQLite (usado en tests)
            DB::table('guild_player')->get()->each(function ($row) {
                $guild = DB::table('guilds')->where('discord_guild_id', $row->guild_id)->first();
                if ($guild) {
                    DB::table('guild_player')
                        ->where('id', $row->id)
                        ->update(['guild_id_new' => $guild->id]);
                }
            });
        }

        // 3. Eliminar filas huérfanas (snowflakes que no tienen Guild)
        DB::table('guild_player')->whereNull('guild_id_new')->delete();

        // 4. Eliminar restricciones antiguas sobre guild_id (índice + unique)
        Schema::table('guild_player', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'guild_id']);

            if (DB::getDriverName() === 'sqlite') {
                // SQLite a veces necesita que se borre el índice explícitamente antes de la columna
                $table->dropIndex('guild_player_guild_id_index');
            }

            $table->dropColumn('guild_id');
        });

        // 5. Renombrar y agregar FK + restricciones
        Schema::table('guild_player', function (Blueprint $table) {
            $table->renameColumn('guild_id_new', 'guild_id');
        });

        Schema::table('guild_player', function (Blueprint $table) {
            $table->uuid('guild_id')->nullable(false)->change();
            $table->foreign('guild_id')->references('id')->on('guilds')->cascadeOnDelete();
            $table->unique(['player_id', 'guild_id']);
            $table->index('guild_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guild_player', function (Blueprint $table) {
            $table->dropForeign(['guild_id']);
            $table->dropUnique(['player_id', 'guild_id']);
            $table->dropIndex(['guild_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE guild_player ALTER COLUMN guild_id TYPE VARCHAR(30)');
        } else {
            Schema::table('guild_player', function (Blueprint $table) {
                $table->uuid('guild_id')->change();
            });
        }
    }
};
