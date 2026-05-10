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
        Schema::dropIfExists('state_change_tags'); // Depende de tags
        Schema::dropIfExists('character_tags');    // Depende de tags
        Schema::dropIfExists('tags');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('character_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('character_id');
            $table->uuid('tag_id');
            $table->timestamps();
        });

        Schema::create('state_change_tags', function (Blueprint $table) {
            $table->uuid('change_id');
            $table->uuid('tag_id');
            $table->primary(['change_id', 'tag_id']);
        });
    }
};
