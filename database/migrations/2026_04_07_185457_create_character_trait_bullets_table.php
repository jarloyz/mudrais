<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_trait_bullets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('character_trait_id')->constrained('character_traits')->onDelete('cascade');
            $table->text('body');
            $table->string('section', 50)->nullable();
            $table->uuid('parent_bullet_id')->nullable();
            $table->integer('legacy_bullet_id')->nullable();
            $table->integer('parent_legacy_bullet_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('character_trait_bullets', function (Blueprint $table) {
            $table->foreign('parent_bullet_id')->references('id')->on('character_trait_bullets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_trait_bullets');
    }
};
