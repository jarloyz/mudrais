<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lore_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vault_id')->index();
            $table->uuid('entity_id')->nullable()->index();
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Note: Vector search is now handled externally via QdrantService.
        // sqlite-vec support was removed according to Task #24.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lore_entries');
    }
};
