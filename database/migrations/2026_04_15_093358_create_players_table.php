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
        Schema::create('players', function (Blueprint $table) {
            $table->uuid('id')->primary(); // BigInt (PK)
            $table->string('discord_id')->unique(); // ID único provisto por Discord.
            $table->string('username'); // Nombre de usuario actual.
            $table->integer('energy')->default(100); // Para turnos de rol.
            $table->integer('coin')->default(0); // Moneda premium.
            $table->integer('elo')->default(1000); // Puntuación de confiabilidad.
            $table->timestamp('last_active_at')->nullable(); // Última interacción.
            $table->boolean('is_active')->default(true); // Estado de la cuenta.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
