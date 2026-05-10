<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sincroniza la secuencia auto-increment de players con el MAX(id) real.
     *
     * Causa: la migración migrate_ecosystem_to_player_id insertó registros con IDs
     * explícitos (id = user->id), lo que no avanza la secuencia de PostgreSQL.
     * Al crear el siguiente player, la secuencia devuelve 1 → duplicate key.
     */
    public function up(): void
    {
        return;

        // pg_get_serial_sequence resuelve el nombre exacto de la secuencia
        // sin hardcodear 'players_id_seq', lo que lo hace robusto ante cambios.
        DB::statement("
            SELECT setval(
                pg_get_serial_sequence('players', 'id'),
                COALESCE((SELECT MAX(id) FROM players), 0) + 1,
                false
            )
        ");
    }

    public function down(): void
    {
        // No hay forma segura de revertir un setval sin conocer el valor anterior.
    }
};
