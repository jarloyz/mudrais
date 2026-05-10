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
        // 1. Rename tables
        Schema::rename('vault_users_memberships', 'vault_player_memberships');
        Schema::rename('scene_users', 'scene_players');
        Schema::rename('user_agent_configs', 'player_agent_configs');

        // 1.5 Migrate existing users to players to maintain integrity
        $users = DB::table('users')->get();
        foreach ($users as $user) {
            DB::table('players')->insert([
                'id' => $user->id,
                'discord_id' => $user->identity_uuid ?? ('legacy_' . $user->id),
                'username' => $user->name,
                'energy' => 100,
                'coin' => 0,
                'elo' => 1000,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]);
        }

        // 2. character_runtime_status
        Schema::table('character_runtime_status', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['user_id']);
            }
            $table->dropUnique('chr_runtime_status_unique');
            $table->renameColumn('user_id', 'player_id');
        });
        Schema::table('character_runtime_status', function (Blueprint $table) {
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            $table->unique(['continuity_id', 'scene_id', 'player_id', 'character_id', 'status_key'], 'chr_runtime_status_unique');
        });

        // 3. vault_player_memberships
        if (DB::getDriverName() === 'sqlite') {
            Schema::rename('vault_player_memberships', 'vault_player_memberships_old');
            Schema::create('vault_player_memberships', function (Blueprint $table) {
                $table->uuid('vault_id');
                $table->uuid('player_id');
                $table->string('role', 20)->default('reader');
                $table->string('status', 20)->default('active');
                $table->uuid('active_continuity_id')->nullable();
                $table->timestamps();
                $table->primary(['vault_id', 'player_id']);
                $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('cascade');
                $table->foreign('player_id')->references('id')->on('players')->onDelete('cascade');
            });
            DB::statement('INSERT INTO vault_player_memberships (vault_id, player_id, role, status, active_continuity_id, created_at, updated_at) SELECT vault_id, user_id as player_id, role, status, active_continuity_id, created_at, updated_at FROM vault_player_memberships_old');
            Schema::drop('vault_player_memberships_old');
        } else {
            // El constraint FK tiene el nombre de la tabla ORIGINAL (antes del rename).
            Schema::table('vault_player_memberships', function (Blueprint $table) {
                DB::statement('ALTER TABLE vault_player_memberships DROP CONSTRAINT IF EXISTS vault_users_memberships_user_id_foreign');
                DB::statement('ALTER TABLE vault_player_memberships DROP CONSTRAINT vault_users_memberships_pkey');
                $table->renameColumn('user_id', 'player_id');
            });
            Schema::table('vault_player_memberships', function (Blueprint $table) {
                $table->primary(['vault_id', 'player_id']);
                $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            });
        }

        // 4. scene_players (renombrada de scene_users → FK original: scene_users_user_id_foreign)
        Schema::table('scene_players', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE scene_players DROP CONSTRAINT IF EXISTS scene_users_user_id_foreign');
            }
            $table->renameColumn('user_id', 'player_id');
        });
        Schema::table('scene_players', function (Blueprint $table) {
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
        });

        // 5. scene_characters (no renombrada → FK: scene_characters_controlled_by_user_id_foreign)
        Schema::table('scene_characters', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['controlled_by_user_id']);
            }
            $table->renameColumn('controlled_by_user_id', 'controlled_by_player_id');
        });
        Schema::table('scene_characters', function (Blueprint $table) {
            $table->foreign('controlled_by_player_id')->references('id')->on('players')->nullOnDelete();
        });

        // 6. player_agent_configs (renombrada de user_agent_configs → FK original: user_agent_configs_user_id_foreign)
        Schema::table('player_agent_configs', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE player_agent_configs DROP CONSTRAINT IF EXISTS user_agent_configs_user_id_foreign');
            }
            $table->renameColumn('user_id', 'player_id');
        });
        Schema::table('player_agent_configs', function (Blueprint $table) {
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Inverse operations
        Schema::table('player_agent_configs', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
            $table->renameColumn('player_id', 'user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('scene_characters', function (Blueprint $table) {
            $table->dropForeign(['controlled_by_player_id']);
            $table->renameColumn('controlled_by_player_id', 'controlled_by_user_id');
            $table->foreign('controlled_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('scene_players', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
            $table->renameColumn('player_id', 'user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('vault_player_memberships', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
            $table->dropPrimary(['vault_id', 'player_id']);
            $table->renameColumn('player_id', 'user_id');
        });
        Schema::table('vault_player_memberships', function (Blueprint $table) {
            $table->primary(['vault_id', 'user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('character_runtime_status', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
            $table->dropUnique('chr_runtime_status_unique');
            $table->renameColumn('player_id', 'user_id');
        });
        Schema::table('character_runtime_status', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['continuity_id', 'scene_id', 'user_id', 'character_id', 'status_key'], 'chr_runtime_status_unique');
        });

        Schema::rename('player_agent_configs', 'user_agent_configs');
        Schema::rename('scene_players', 'scene_users');
        Schema::rename('vault_player_memberships', 'vault_users_memberships');
    }
};
