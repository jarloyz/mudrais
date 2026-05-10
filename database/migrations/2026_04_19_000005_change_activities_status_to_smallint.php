<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2.6 – Manejo de Estados con Backed Enums (PHP 8.1+)
 *
 * NOTA: Esta migración ha sido neutralizada para mantener status como string.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No hacer nada, mantener status como string
    }

    public function down(): void
    {
        // No hacer nada
    }
};
