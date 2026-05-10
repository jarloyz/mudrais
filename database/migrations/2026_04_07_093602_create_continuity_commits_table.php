<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('continuity_commits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('continuity_id');
            $table->uuid('scene_id');
            $table->uuid('parent_commit_id')->nullable();
            $table->uuid('source_parent_commit_id')->nullable();
            $table->integer('turn_index')->nullable();
            $table->string('mode', 50)->default('write_scene');
            $table->text('message');
            $table->timestamps();

            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
        });

        Schema::table('continuity_commits', function (Blueprint $table) {
            $table->foreign('parent_commit_id')->references('id')->on('continuity_commits')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('continuity_commits');
    }
};
