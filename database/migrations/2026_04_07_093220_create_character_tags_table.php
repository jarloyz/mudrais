<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equivalent to tags and character_tags in 001_init.sql
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 50)->unique();
        });

        Schema::create('character_tags', function (Blueprint $table) {
            $table->uuid('character_id');
            $table->uuid('tag_id');
            $table->uuid('context_id')->nullable(); // Si mantenemos contexts (timeline overrides)
            $table->timestamps();

            $table->primary(['character_id', 'tag_id']); // Simplified for now without contexts in primary key
            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_tags');
        Schema::dropIfExists('tags');
    }
};
