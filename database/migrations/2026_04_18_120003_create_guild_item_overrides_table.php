<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guild_item_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('guild_id');
            $table->string('item_key');
            $table->integer('coin_delta')->nullable();
            $table->integer('energy_delta')->nullable();
            $table->boolean('is_active')->nullable();
            $table->timestamps();

            $table->unique(['guild_id', 'item_key']);
            $table->index('guild_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_item_overrides');
    }
};
