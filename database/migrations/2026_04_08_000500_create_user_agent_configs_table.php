<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('writer_model')->nullable();
            $table->string('qa_model')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_agent_configs');
    }
};
