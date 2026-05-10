<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vault_id')->unique();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->foreign('vault_id')
                ->references('id')->on('vaults')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_agent_configs');
    }
};
