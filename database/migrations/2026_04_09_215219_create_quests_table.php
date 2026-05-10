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
        Schema::create('quests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vault_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 50)->default('main'); // main, side, faction
            $table->string('status', 50)->default('active'); // active, archived
            $table->timestamps();

            $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quests');
    }
};
