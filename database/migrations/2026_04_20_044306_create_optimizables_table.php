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
        Schema::create('optimizables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('optimizable_type');
            $table->uuid('optimizable_id');
            $table->text('optimized_text');
            $table->timestamps();

            $table->index(['optimizable_type', 'optimizable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('optimizables');
    }
};
