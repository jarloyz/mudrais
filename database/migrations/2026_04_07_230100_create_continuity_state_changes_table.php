<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('continuity_state_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('continuity_id');
            $table->uuid('scene_id');
            $table->string('kind', 50)->default('state');
            $table->string('scope_type', 50)->default('scene');
            $table->uuid('scope_id')->nullable();
            $table->text('change');
            $table->integer('severity')->default(1);
            $table->timestamps();

            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('continuity_state_changes');
    }
};
