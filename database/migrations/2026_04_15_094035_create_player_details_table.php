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
        Schema::create('player_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->integer('age')->nullable();
            $table->string('nationality')->nullable();
            $table->string('experience_level')->nullable(); // Novato, Veterano, Máster.
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('schedule')->nullable();
                $table->jsonb('red_lines')->nullable();
                $table->jsonb('affinities')->nullable();
            } else {
                $table->json('schedule')->nullable();
                $table->json('red_lines')->nullable();
                $table->json('affinities')->nullable();
            }
            $table->string('verbosity')->nullable(); // Extensión preferida.
            $table->text('raw_profile')->nullable(); // Texto íntegro y estilo narrativo.
            $table->boolean('is_vectorized')->default(false); // Estado de sincronización con Qdrant.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_details');
    }
};
