<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de Instantáneas de Personaje (Character Snapshots).
     *
     * Congela el estado de un personaje (stats, inventario, bullets) en el momento
     * en que entra a una escena, aislando las partidas en curso de ediciones
     * posteriores en el Baúl.
     */
    public function up(): void
    {
        Schema::create('character_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('scene_id')->index();
            $table->uuid('character_id')->index();

            // JSON con HP, Inventario, Stats, Bullets y Backgrounds al momento de entrar
            $table->json('snapshot_data');

            // Versión incremental: permite detectar re-snapshots dentro de la misma escena
            $table->unsignedInteger('version')->default(1);

            $table->timestamp('snapshotted_at')->useCurrent();
            $table->timestamps();

            // Una instantánea activa por personaje por escena
            $table->unique(['scene_id', 'character_id']);

            $table->foreign('scene_id')
                ->references('id')
                ->on('scenes')
                ->cascadeOnDelete();

            $table->foreign('character_id')
                ->references('id')
                ->on('characters')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_instances');
    }
};
