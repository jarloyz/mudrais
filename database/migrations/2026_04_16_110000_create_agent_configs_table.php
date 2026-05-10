<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 20);
            $table->uuid('player_id')->nullable();
            $table->uuid('vault_id')->nullable();
            $table->uuid('scene_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            $table->foreign('vault_id')->references('id')->on('vaults')->cascadeOnDelete();
            $table->foreign('scene_id')->references('id')->on('scenes')->cascadeOnDelete();

            $table->index(['scope', 'player_id']);
            $table->index(['scope', 'vault_id']);
            $table->index(['scope', 'scene_id']);
        });

        // Partial unique index para la fila global (solo PostgreSQL soporta WHERE en índices)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX agent_configs_global_unique ON agent_configs (scope) WHERE scope = 'global'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_configs');
    }
};
