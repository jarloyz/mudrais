<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            // Texto original del usuario (vibra/estilo narrativo, antes de procesar)
            $table->text('raw_profile')->nullable()->after('red_lines');
            // Perfil de preferencia procesado, equivalente al narrative_style_text de players
            $table->text('preference_profile')->nullable()->after('raw_profile');
            // Límites blandos, específicos por arquetipo
            $table->json('yellow_lines')->nullable()->after('preference_profile');
            // Disponibilidad horaria — varía por arquetipo (D&D fines de semana vs gaming diario)
            $table->json('schedule')->nullable()->after('yellow_lines');
            $table->text('schedule_raw')->nullable()->after('schedule');
            // Control de sincronización con Qdrant
            $table->boolean('is_vectorized')->default(false)->after('schedule_raw');
        });
    }

    public function down(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'raw_profile',
                'preference_profile',
                'yellow_lines',
                'schedule',
                'schedule_raw',
                'is_vectorized',
            ]);
        });
    }
};
