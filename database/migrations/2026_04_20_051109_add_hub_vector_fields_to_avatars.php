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
        Schema::table('avatars', function (Blueprint $table) {
            $table->json('avatar_context_vector')->nullable();
            $table->uuid('avatar_hub_qdrant_id')->nullable();
            $table->boolean('is_hub_indexed')->default(false);
            $table->boolean('is_lfg')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_context_vector',
                'avatar_hub_qdrant_id',
                'is_hub_indexed',
                'is_lfg'
            ]);
        });
    }
};
