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
        Schema::create('guild_command_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guild_id')->constrained('guilds')->cascadeOnDelete();
            $table->string('command_name', 50);
            $table->unsignedSmallInteger('energy_cost')->default(0);
            $table->timestamps();

            $table->unique(['guild_id', 'command_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guild_command_costs');
    }
};
