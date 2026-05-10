<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_runtime_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('continuity_id');
            $table->uuid('scene_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('character_id');
            $table->string('status_key', 100);
            $table->float('status_value')->nullable();
            $table->text('status_text')->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('source', 50)->default('system'); // system|gm|event|import
            $table->timestamps();

            $table->unique(['continuity_id', 'scene_id', 'user_id', 'character_id', 'status_key'], 'chr_runtime_status_unique');

            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_runtime_status');
    }
};
