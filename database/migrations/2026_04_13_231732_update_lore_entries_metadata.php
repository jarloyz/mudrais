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
        Schema::table('lore_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('lore_entries', 'entity_id')) {
                $table->uuid('entity_id')->nullable()->after('vault_id')->index();
            }
            if (Schema::hasColumn('lore_entries', 'metadata')) {
                // If metadata is already there, we just ensure it handles JSON well.
                // In some DBs we might want to cast or set a default.
                // For PostgreSQL, we can leave it as JSON or JSONB.
                // We'll just add entity_id as the primary change.
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lore_entries', function (Blueprint $table) {
            if (Schema::hasColumn('lore_entries', 'entity_id')) {
                $table->dropColumn('entity_id');
            }
        });
    }
};
