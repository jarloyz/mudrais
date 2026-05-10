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
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->string('semantic_tag_query', 500)->nullable()->after('metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->dropColumn('semantic_tag_query');
        });
    }
};
