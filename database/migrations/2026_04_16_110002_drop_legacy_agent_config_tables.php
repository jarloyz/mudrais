<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('scene_agent_configs');
        Schema::dropIfExists('vault_agent_configs');
        Schema::dropIfExists('player_agent_configs');
        Schema::dropIfExists('global_agent_config');
    }

    public function down(): void
    {
        Schema::create('global_agent_config', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::create('player_agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('player_id')->unique();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::create('vault_agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vault_id')->unique();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::create('scene_agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('scene_id')->unique();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });
    }
};
