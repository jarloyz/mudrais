<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Elimina las tablas de conocimiento legacy basadas en sqlite-vec.
     * Según el Master Blueprint y la Tarea #24, todo el lore debe migrar a lore_entries (PostgreSQL + Qdrant).
     */
    public function up(): void
    {
        // 1. Migrar datos de knowledge_chunks a lore_entries si existen
        if (Schema::hasTable('knowledge_chunks')) {
            $chunks = DB::table('knowledge_chunks')->get();
            foreach ($chunks as $chunk) {
                // Evitar duplicados si ya se migraron manualmente
                $exists = DB::table('lore_entries')
                    ->where('vault_id', $chunk->vault_id)
                    ->where('content', $chunk->content)
                    ->exists();

                if (!$exists) {
                    DB::table('lore_entries')->insert([
                        'vault_id' => $chunk->vault_id,
                        'content' => $chunk->content,
                        'metadata' => $chunk->metadata,
                        'created_at' => $chunk->created_at,
                        'updated_at' => $chunk->updated_at,
                    ]);
                }
            }
        }

        // 2. Eliminar tablas virtuales de vectores (sqlite-vec)
        try {
            DB::statement("DROP TABLE IF EXISTS knowledge_chunks_vec");
        } catch (\Exception $e) {}

        try {
            DB::statement("DROP TABLE IF EXISTS lore_vec_idx");
        } catch (\Exception $e) {}

        // 3. Eliminar tabla knowledge_chunks
        Schema::dropIfExists('knowledge_chunks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No revertimos el soporte de sqlite-vec ya que ha sido deprecado.
    }
};
