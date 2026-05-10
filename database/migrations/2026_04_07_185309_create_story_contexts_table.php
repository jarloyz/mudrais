<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200)->nullable();
            $table->string('label', 200)->nullable();
            $table->uuid('legacy_context_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_contexts');
    }
};
