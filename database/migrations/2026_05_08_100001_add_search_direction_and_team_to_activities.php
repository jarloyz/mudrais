<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // search_direction: outbound (jugadores encuentran la actividad),
            // inbound (la actividad busca jugadores), both (bidireccional).
            // Default outbound preserva el comportamiento actual para actividades existentes.
            $table->string('search_direction', 10)->default('outbound')->after('requires_avatar');

            // Auto-referencia para team search: una actividad padre agrupa N slots hijos.
            $table->uuid('parent_activity_id')->nullable()->after('search_direction');
            $table->foreign('parent_activity_id')
                ->references('id')
                ->on('activities')
                ->nullOnDelete();

            // Número de slots requeridos para completar el equipo (null = búsqueda individual).
            $table->smallInteger('required_slots')->unsigned()->nullable()->after('parent_activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['parent_activity_id']);
            $table->dropColumn(['search_direction', 'parent_activity_id', 'required_slots']);
        });
    }
};
