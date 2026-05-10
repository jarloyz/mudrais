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
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->string('default_model', 255)->nullable()->after('base_url')
                ->comment('Modelo por defecto cuando el proveedor sirve un único modelo (ej. servidores AMD por puerto).');
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn('default_model');
        });
    }
};
