<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            // Almacena los valores raw de los mutadores de registration (context='registration').
            // Permite a MatchingFilterService filtrar por cualquier campo del formulario
            // usando JSONB: (content_raw->>'field_key')::text = 'value'
            // Ejemplo: content_raw->>'is_writer' = 'true'
            $table->json('content_raw')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->dropColumn('content_raw');
        });
    }
};
