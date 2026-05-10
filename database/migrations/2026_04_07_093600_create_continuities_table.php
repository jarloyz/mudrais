<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('continuities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->uuid('root_id');
            $table->string('label')->default('');
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // FK auto-referencial en statement separado: PostgreSQL requiere que
        // la tabla (y su PK) existan completamente antes de validar la referencia.
        Schema::table('continuities', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('continuities')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('continuities');
    }
};
