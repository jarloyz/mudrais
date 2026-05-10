<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_traits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('character_id');
            $table->uuid('context_id')->nullable();
            $table->string('trait_key', 50)->default('trait'); // trait, background, relationship, etc
            $table->string('title', 200);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();

            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
            $table->foreign('context_id')->references('id')->on('story_contexts')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_traits');
    }
};
