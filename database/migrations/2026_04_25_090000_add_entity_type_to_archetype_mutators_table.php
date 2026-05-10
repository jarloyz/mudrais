<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetype_mutators', function (Blueprint $table) {
            $table->dropUnique(['archetype_id', 'context', 'field_key']);

            $table->foreignUuid('archetype_entity_type_id')
                ->nullable()
                ->after('archetype_id')
                ->constrained('archetype_entity_types')
                ->cascadeOnDelete();

            // Unique por entity type específico (activities_vibe, avatar_context)
            $table->unique(['archetype_entity_type_id', 'context', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::table('archetype_mutators', function (Blueprint $table) {
            $table->dropUnique(['archetype_entity_type_id', 'context', 'field_key']);
            $table->dropConstrainedForeignId('archetype_entity_type_id');
            $table->unique(['archetype_id', 'context', 'field_key']);
        });
    }
};
