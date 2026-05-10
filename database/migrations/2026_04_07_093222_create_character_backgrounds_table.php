<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_backgrounds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('character_id');
            $table->uuid('context_id')->nullable();
            $table->string('category', 50)->default('general'); // origin|family|education|work|event|trauma|relationship|sexual|general|custom
            $table->string('title')->nullable();
            $table->text('summary');
            $table->text('detail')->nullable();
            $table->boolean('is_sexual')->default(false);
            $table->integer('importance')->default(1); // 1-5
            $table->string('source_trait_key', 100)->nullable();
            $table->uuid('source_bullet_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
            $table->foreign('source_bullet_id')->references('id')->on('character_bullets')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_backgrounds');
    }
};
