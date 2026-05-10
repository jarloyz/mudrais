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
        Schema::create('continuity_quest_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('continuity_id');
            $table->uuid('scene_id')->nullable();
            $table->uuid('quest_id');
            $table->string('status', 50)->default('active'); // active, completed, failed, hidden
            $table->integer('current_stage_number')->default(0);
            $table->text('ai_summary')->nullable();
            $table->timestamps();

            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('set null');
            $table->foreign('quest_id')->references('id')->on('quests')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('continuity_quest_statuses');
    }
};
