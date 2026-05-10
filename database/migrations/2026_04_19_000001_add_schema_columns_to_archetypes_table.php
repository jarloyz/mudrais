<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            // Define qué campos dinámicos espera este arquetipo (para validación y UI dinámica)
            $table->json('metadata_schema')->nullable()->after('qdrant_vector_name');
            // Estructura del Modal de Discord que se mostrará durante /registro
            $table->json('registration_modal_schema')->nullable()->after('metadata_schema');
        });
    }

    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropColumn(['metadata_schema', 'registration_modal_schema']);
        });
    }
};
