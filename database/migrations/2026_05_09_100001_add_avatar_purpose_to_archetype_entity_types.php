<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetype_entity_types', function (Blueprint $table) {
            $table->enum('avatar_purpose', ['role', 'context'])
                  ->nullable()
                  ->after('entity')
                  ->comment('Solo para entity=avatar. role=query vector contra player_style; context=blend modifier (30%).');
        });
    }

    public function down(): void
    {
        Schema::table('archetype_entity_types', function (Blueprint $table) {
            $table->dropColumn('avatar_purpose');
        });
    }
};
