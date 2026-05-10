<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Añadir columna nullable temporalmente
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->uuid('player_id')->after('id')->nullable();
        });

        // 2. Backfill de datos usando discord_user_id
        DB::statement('
            UPDATE player_archetype_profiles
            SET player_id = (SELECT id FROM players WHERE players.discord_id = player_archetype_profiles.discord_user_id)
        ');

        // 3. Limpiar registros que no encontraron player (huérfanos) para poder poner NOT NULL
        // Si hay perfiles sin jugador, los borramos para mantener integridad
        DB::table('player_archetype_profiles')->whereNull('player_id')->delete();

        // 4. Aplicar restricciones y FK
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->uuid('player_id')->nullable(false)->change();

            // Eliminar el unique viejo basado en discord_user_id si existe
            $table->dropUnique(['discord_user_id', 'archetype_id']);

            // Nuevo unique basado en player_id
            $table->unique(['player_id', 'archetype_id'], 'pap_player_archetype_unique');

            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
            $table->dropUnique('pap_player_archetype_unique');
            $table->unique(['discord_user_id', 'archetype_id']);
            $table->dropColumn('player_id');
        });
    }
};
