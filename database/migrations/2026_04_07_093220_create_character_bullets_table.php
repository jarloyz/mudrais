<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_bullets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('character_id');
            $table->uuid('context_id')->nullable();
            $table->string('trait_key', 100)->nullable();
            $table->string('section', 100)->nullable();
            $table->uuid('parent_bullet_id')->nullable();
            $table->text('content');
            $table->string('bullet_type', 50)->default('profile'); // profile|background|sexual|voice|physical|history|custom
            $table->boolean('is_sexual')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
        });

        Schema::table('character_bullets', function (Blueprint $table) {
            $table->foreign('parent_bullet_id')->references('id')->on('character_bullets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_bullets');
    }
};
