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
        // 1. Crear tabla archetype_vault
        Schema::create('archetype_vault', function (Blueprint $table) {
            $table->uuid('vault_id');
            $table->uuid('archetype_id');
            $table->uuid('guild_id')->nullable(); // Para saber qué guild trajo este arquetipo al vault
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['vault_id', 'archetype_id']);
            $table->foreign('vault_id')->references('id')->on('vaults')->cascadeOnDelete();
            $table->foreign('archetype_id')->references('id')->on('archetypes')->cascadeOnDelete();
            $table->foreign('guild_id')->references('id')->on('guilds')->nullOnDelete();
        });

        // 2. Backfill de datos desde vaults.archetype_id
        // Asumimos que el arquetipo actual del vault es el primario
        DB::statement('
            INSERT INTO archetype_vault (vault_id, archetype_id, is_primary, created_at, updated_at)
            SELECT id, archetype_id, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM vaults
            WHERE archetype_id IS NOT NULL
        ');

        // 3. Eliminar columna redundante de vaults
        Schema::table('vaults', function (Blueprint $table) {
            $table->dropForeign(['archetype_id']);
            $table->dropColumn('archetype_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            $table->uuid('archetype_id')->nullable();
            $table->foreign('archetype_id')->references('id')->on('archetypes')->nullOnDelete();
        });

        // Restaurar datos si es posible
        DB::statement('
            UPDATE vaults
            SET archetype_id = (SELECT archetype_id FROM archetype_vault WHERE archetype_vault.vault_id = vaults.id AND is_primary = true LIMIT 1)
        ');

        Schema::dropIfExists('archetype_vault');
    }
};
