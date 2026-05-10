<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_active_continuities', function (Blueprint $table) {
            $table->uuid('scene_id')->primary();
            $table->uuid('continuity_id');
            $table->uuid('continuity_commit_id')->nullable();
            $table->timestamps();

            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('continuity_commit_id')->references('id')->on('continuity_commits')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_active_continuities');
    }
};
