<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PREREQUISITO: Ejecutar MigratePlayerDataToArchetypeProfilesCommand ANTES de esta migración
 * para asegurar que todos los datos contextuales estén copiados en player_archetype_profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Campos contextuales/por-arquetipo: ahora viven en player_archetype_profiles
            $table->dropColumn([
                'experience_level',
                'verbosity_level',
                'schedule_raw',
                'narrative_style_text',
                'schedule',
                'red_lines',
                'affinities',
                'raw_profile',
                'is_vectorized',
                'yellow_lines',
            ]);

            // Nuevo campo de moderación global
            $table->boolean('global_banned')->default(false)->after('tutorial_completed');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('global_banned');

            $table->unsignedTinyInteger('experience_level')->nullable();
            $table->unsignedTinyInteger('verbosity_level')->nullable();
            $table->string('schedule_raw')->nullable();
            $table->text('narrative_style_text')->nullable();
            $table->json('schedule')->nullable();
            $table->json('red_lines')->nullable();
            $table->json('affinities')->nullable();
            $table->text('raw_profile')->nullable();
            $table->boolean('is_vectorized')->default(false);
            $table->json('yellow_lines')->nullable();
        });
    }
};
