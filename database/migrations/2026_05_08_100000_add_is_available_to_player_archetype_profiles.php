<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('is_vectorized');
            $table->index(['archetype_id', 'is_available'], 'pap_archetype_available_idx');
        });
    }

    public function down(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->dropIndex('pap_archetype_available_idx');
            $table->dropColumn('is_available');
        });
    }
};
