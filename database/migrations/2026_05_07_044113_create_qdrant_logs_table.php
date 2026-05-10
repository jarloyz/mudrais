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
        Schema::create('qdrant_logs', function (Blueprint $table) {
            $table->id();
            $table->string('collection_name')->nullable();
            $table->string('operation');
            $table->float('latency_ms');
            $table->integer('matches_count')->nullable();
            $table->string('status');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qdrant_logs');
    }
};
