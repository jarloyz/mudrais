<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignUuid('archetype_entity_type_id')
                ->nullable()
                ->after('activity_description')
                ->constrained('archetype_entity_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['archetype_entity_type_id']);
            $table->dropColumn('archetype_entity_type_id');
        });
    }
};
