<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versionado vectorial para Herencia y Líneas Temporales (VTT).
     *
     * lineage_id   — ID del personaje/entidad base que originó esta entrada.
     * version_start — Versión a partir de la cual esta entrada es válida.
     * version_end   — Versión a partir de la cual se invalida (null = vigente).
     *
     * Filtro RAG: lineage_id = X
     *             AND version_start <= N
     *             AND (version_end IS NULL OR version_end >= N)
     */
    public function up(): void
    {
        Schema::table('lore_entries', function (Blueprint $table) {
            // ID del personaje/entidad raíz que genera esta línea de lore
            $table->uuid('lineage_id')->nullable()->after('entity_id')->index();

            // Rango de versiones en que esta entrada es válida
            $table->unsignedInteger('version_start')->default(1)->after('lineage_id');
            $table->unsignedInteger('version_end')->nullable()->after('version_start');
        });
    }

    public function down(): void
    {
        Schema::table('lore_entries', function (Blueprint $table) {
            $table->dropIndex(['lineage_id']);
            $table->dropColumn(['lineage_id', 'version_start', 'version_end']);
        });
    }
};
