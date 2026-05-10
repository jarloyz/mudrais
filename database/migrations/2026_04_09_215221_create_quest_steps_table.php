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
        Schema::create('quest_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quest_id');
            $table->integer('stage_number');
            $table->text('description');
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->unique(['quest_id', 'stage_number']);
            $table->foreign('quest_id')->references('id')->on('quests')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quest_steps');
    }
};
