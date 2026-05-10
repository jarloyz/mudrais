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
        Schema::dropIfExists('character_trait_bullets');
        Schema::dropIfExists('character_traits');

        Schema::table('characters', function (Blueprint $table) {
            $table->text('public_facade')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('public_facade');
        });
        // Recrear las tablas si es necesario revertir
        // Omitido para mantener la limpieza.
    }
};
