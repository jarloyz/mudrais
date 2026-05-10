<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetype_entity_types', function (Blueprint $table) {
            $table->json('matching_filters')->nullable()->after('system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('archetype_entity_types', function (Blueprint $table) {
            $table->dropColumn('matching_filters');
        });
    }
};
