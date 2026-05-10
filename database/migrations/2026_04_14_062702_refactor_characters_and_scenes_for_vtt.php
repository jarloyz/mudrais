<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1.1 Modificar tabla characters
        Schema::table('characters', function (Blueprint $table) {
            // Eliminar si existen, aunque no se hayan visto en migraciones previas de Laravel
            if (Schema::hasColumn('characters', 'user_id')) {
                $table->dropColumn('user_id');
            }
            if (Schema::hasColumn('characters', 'is_npc')) {
                $table->dropColumn('is_npc');
            }
        });

        // 1.2 Modificar tabla scenes
        Schema::table('scenes', function (Blueprint $table) {
            $table->uuid('current_turn_character_id')->nullable();
            $table->integer('round_number')->default(1);

            $table->foreign('current_turn_character_id')
                ->references('id')
                ->on('characters')
                ->onDelete('set null');
        });

        // 1.3 Modificar tabla pivote scene_characters
        Schema::table('scene_characters', function (Blueprint $table) {
            $table->foreignUuid('controlled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scene_role')->default('npc'); // 'player', 'npc', 'guest'
            $table->integer('initiative_score')->default(0);
            $table->boolean('has_acted_this_round')->default(false);

            // Si queremos mantener compatibilidad con el 'role' anterior, podríamos migrarlo o dejarlo.
            // La instrucción sugiere usar scene_role.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scene_characters', function (Blueprint $table) {
            $table->dropForeign(['controlled_by_user_id']);
            $table->dropColumn(['controlled_by_user_id', 'scene_role', 'initiative_score', 'has_acted_this_round']);
        });

        Schema::table('scenes', function (Blueprint $table) {
            $table->dropForeign(['current_turn_character_id']);
            $table->dropColumn(['current_turn_character_id', 'round_number']);
        });

        Schema::table('characters', function (Blueprint $table) {
            // No revertimos el drop de columnas que no estaban en las migraciones originales de Laravel
        });
    }
};
