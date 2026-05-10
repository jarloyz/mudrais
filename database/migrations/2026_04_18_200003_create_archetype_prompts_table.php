<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archetype_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('archetype_id')->constrained('archetypes');
            $table->enum('agent_type', ['gatekeeper', 'optimizer']);
            $table->text('system_prompt');
            $table->timestamps();

            $table->index('archetype_id');
            $table->unique(['archetype_id', 'agent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archetype_prompts');
    }
};
