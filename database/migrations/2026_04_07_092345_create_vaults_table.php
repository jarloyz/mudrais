<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaults', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('status', 50)->default('active');
            $table->text('description')->nullable();

            // world_notes and agent_instructions as JSON (SQLite text with json payload)
            $table->json('world_notes')->nullable();
            $table->json('agent_instructions')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaults');
    }
};
