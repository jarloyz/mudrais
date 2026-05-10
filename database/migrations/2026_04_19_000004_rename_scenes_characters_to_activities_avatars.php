<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: El Gran Renombrado (Punto 2.4 de la propuesta de arquitectura)
 *
 * - scenes      → activities   (modelo Activity)
 * - characters  → avatars      (modelo Avatar)
 * - scene_characters + scene_users → activity_members (tabla pivote unificada)
 *
 * PREREQUISITO: Correr mudrais:migrate-player-profiles antes de ejecutar
 * las migraciones de Fase 2 (000003). Esta migración va después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Log::info('[Migration:000004] Fase 3 – Renombrado de tablas iniciado');

        // 1. Eliminar tablas pivote antiguas antes de renombrar las tablas padre
        Schema::dropIfExists('scene_characters');
        Schema::dropIfExists('scene_users');

        // 2. Renombrar tablas principales
        Schema::rename('scenes', 'activities');
        Schema::rename('characters', 'avatars');

        // 3. Añadir columna de pertenencia B2B a activities
        Schema::table('activities', function (Blueprint $table): void {
            $table->uuid('creator_profile_id')->nullable()->after('vault_id');
            $table->foreign('creator_profile_id')
                ->references('id')->on('player_archetype_profiles')
                ->nullOnDelete();
        });

        // 4. Añadir columna de pertenencia B2B a avatars
        Schema::table('avatars', function (Blueprint $table): void {
            $table->uuid('owner_profile_id')->nullable()->after('vault_id');
            $table->foreign('owner_profile_id')
                ->references('id')->on('player_archetype_profiles')
                ->nullOnDelete();
        });

        // 5. Renombrar scene_id → activity_id en tablas de continuidad
        Schema::table('scene_active_continuities', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });
        Schema::table('continuity_commits', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });
        Schema::table('continuity_scene_states', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });
        Schema::table('continuity_commit_scene_states', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });
        Schema::table('continuity_turns', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });
        Schema::table('continuity_state_changes', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });
        Schema::table('character_runtime_status', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });
        Schema::table('continuity_quest_statuses', function (Blueprint $table): void {
            $table->renameColumn('scene_id', 'activity_id');
        });

        // 6. Renombrar columna pivot event_characters: character_id → avatar_id
        Schema::table('event_characters', function (Blueprint $table): void {
            $table->renameColumn('character_id', 'avatar_id');
        });

        // 6. Crear tabla pivote unificada activity_members
        //    Si requires_avatar = false → se rellena player_archetype_profile_id
        //    Si requires_avatar = true  → se rellena avatar_id
        Schema::create('activity_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('activity_id');
            $table->uuid('player_archetype_profile_id')->nullable();
            $table->uuid('avatar_id')->nullable();
            $table->string('role', 50)->nullable();
            $table->string('scene_role', 50)->default('player'); // player | npc | guest | admin
            $table->uuid('controlled_by_player_id')->nullable();
            $table->integer('initiative_score')->default(0);
            $table->boolean('has_acted_this_round')->default(false);
            $table->timestamps();

            $table->foreign('activity_id')
                ->references('id')->on('activities')->cascadeOnDelete();
            $table->foreign('player_archetype_profile_id')
                ->references('id')->on('player_archetype_profiles')->nullOnDelete();
            $table->foreign('avatar_id')
                ->references('id')->on('avatars')->nullOnDelete();
            $table->foreign('controlled_by_player_id')
                ->references('id')->on('players')->nullOnDelete();
        });

        Log::info('[Migration:000004] Fase 3 – Renombrado completado');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_members');

        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropForeign(['owner_profile_id']);
            $table->dropColumn('owner_profile_id');
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->dropForeign(['creator_profile_id']);
            $table->dropColumn('creator_profile_id');
        });

        Schema::rename('avatars', 'characters');
        Schema::rename('activities', 'scenes');

        Schema::create('scene_characters', function (Blueprint $table): void {
            $table->uuid('scene_id');
            $table->uuid('character_id');
            $table->string('role', 50)->nullable();
            $table->uuid('controlled_by_player_id')->nullable();
            $table->string('scene_role', 50)->default('npc');
            $table->integer('initiative_score')->default(0);
            $table->boolean('has_acted_this_round')->default(false);
            $table->timestamps();

            $table->primary(['scene_id', 'character_id']);
            $table->foreign('scene_id')->references('id')->on('scenes')->cascadeOnDelete();
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
            $table->foreign('controlled_by_player_id')->references('id')->on('players')->nullOnDelete();
        });
    }
};
