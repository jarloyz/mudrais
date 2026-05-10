<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('continuity_commit_scene_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('commit_id');
            $table->uuid('continuity_id');
            $table->uuid('scene_id');
            $table->text('objective')->nullable();
            $table->text('constraints')->nullable();
            $table->text('draft');
            $table->timestamps();

            $table->unique(['commit_id', 'scene_id']);
            $table->foreign('commit_id')->references('id')->on('continuity_commits')->onDelete('cascade');
            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('continuity_commit_scene_states');
    }
};
