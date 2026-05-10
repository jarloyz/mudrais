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
        Schema::table('vaults', function (Blueprint $table) {
            $table->json('vault_setting_vector')->nullable();
            $table->uuid('vault_hub_qdrant_id')->nullable();
            $table->boolean('is_hub_indexed')->default(false);
            $table->foreignUuid('archetype_id')->nullable()->constrained('archetypes')->nullOnDelete();
            $table->boolean('is_public')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            $table->dropForeign(['archetype_id']);
            $table->dropColumn([
                'vault_setting_vector',
                'vault_hub_qdrant_id',
                'is_hub_indexed',
                'archetype_id',
                'is_public'
            ]);
        });
    }
};
