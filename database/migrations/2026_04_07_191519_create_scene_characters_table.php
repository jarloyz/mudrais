<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_characters', function (Blueprint $table) {
            $table->uuid('scene_id');
            $table->uuid('character_id');
            $table->string('role', 50)->nullable();
            $table->timestamps();

            $table->primary(['scene_id', 'character_id']);
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_characters');
    }
};
