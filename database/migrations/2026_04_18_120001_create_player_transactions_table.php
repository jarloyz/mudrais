<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained('players')->cascadeOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->unsignedInteger('amount');
            $table->string('description');
            $table->integer('balance_after');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_transactions');
    }
};
