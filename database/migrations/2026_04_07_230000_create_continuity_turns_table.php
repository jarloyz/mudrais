<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('continuity_turns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('continuity_id');
            $table->uuid('scene_id');
            $table->integer('turn_index');
            $table->string('mode', 50)->default('write_scene');
            $table->text('user_message');
            $table->text('output_md');
            $table->json('notes_json')->nullable();
            $table->timestamps();

            $table->unique(['continuity_id', 'scene_id', 'turn_index']);
            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('continuity_turns');
    }
};
