<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('global_agent_config')) {
            DB::table('global_agent_config')->orderBy('id')->each(function (object $row): void {
                DB::table('agent_configs')->insert([
                    'scope'         => 'global',
                    'player_id'     => null,
                    'vault_id'      => null,
                    'scene_id'      => null,
                    'provider'      => $row->provider,
                    'writer_model'  => $row->writer_model,
                    'qa_model'      => $row->qa_model,
                    'timeout_ms'    => $row->timeout_ms,
                    'settings_json' => $row->settings_json,
                    'created_at'    => $row->created_at,
                    'updated_at'    => $row->updated_at,
                ]);
            });
        }

        if (Schema::hasTable('player_agent_configs')) {
            DB::table('player_agent_configs')->orderBy('id')->each(function (object $row): void {
                DB::table('agent_configs')->insert([
                    'scope'         => 'player',
                    'player_id'     => $row->player_id,
                    'vault_id'      => null,
                    'scene_id'      => null,
                    'provider'      => $row->provider,
                    'writer_model'  => $row->writer_model,
                    'qa_model'      => $row->qa_model,
                    'timeout_ms'    => $row->timeout_ms,
                    'settings_json' => $row->settings_json,
                    'created_at'    => $row->created_at,
                    'updated_at'    => $row->updated_at,
                ]);
            });
        }

        if (Schema::hasTable('vault_agent_configs')) {
            DB::table('vault_agent_configs')->orderBy('id')->each(function (object $row): void {
                DB::table('agent_configs')->insert([
                    'scope'         => 'vault',
                    'player_id'     => null,
                    'vault_id'      => $row->vault_id,
                    'scene_id'      => null,
                    'provider'      => $row->provider,
                    'writer_model'  => $row->writer_model,
                    'qa_model'      => $row->qa_model,
                    'timeout_ms'    => $row->timeout_ms,
                    'settings_json' => $row->settings_json,
                    'created_at'    => $row->created_at,
                    'updated_at'    => $row->updated_at,
                ]);
            });
        }

        if (Schema::hasTable('scene_agent_configs')) {
            DB::table('scene_agent_configs')->orderBy('id')->each(function (object $row): void {
                DB::table('agent_configs')->insert([
                    'scope'         => 'scene',
                    'player_id'     => null,
                    'vault_id'      => null,
                    'scene_id'      => $row->scene_id,
                    'provider'      => $row->provider,
                    'writer_model'  => $row->writer_model,
                    'qa_model'      => $row->qa_model,
                    'timeout_ms'    => $row->timeout_ms,
                    'settings_json' => $row->settings_json,
                    'created_at'    => $row->created_at,
                    'updated_at'    => $row->updated_at,
                ]);
            });
        }
    }

    public function down(): void
    {
        DB::table('agent_configs')->truncate();
    }
};
