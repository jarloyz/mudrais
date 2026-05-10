<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->uuid('id')->primary(); // varchar(50) PRIMARY KEY
            $table->string('name', 100);
            $table->uuid('vault_id')->nullable();
            $table->timestamps();

            // Si referenciamos a vaults, asegurarnos que exista. Asumimos que la tabla vaults ya fue creada.
            $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
