<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_configs', function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('scope');
            $table->boolean('active')->default(false)->after('name');
        });

        // Eliminar el índice único parcial que impedía múltiples filas con scope='global'
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS agent_configs_global_unique');
        }

        // Marcar la fila global existente como activa con nombre por defecto
        DB::table('agent_configs')
            ->where('scope', 'global')
            ->update(['active' => true, 'name' => 'Default']);
    }

    public function down(): void
    {
        // Dejar solo la fila activa si hay varias y restaurar el índice único
        if (DB::getDriverName() === 'pgsql') {
            // Eliminar filas globales no activas antes de restaurar el unique
            DB::table('agent_configs')
                ->where('scope', 'global')
                ->where('active', false)
                ->delete();

            DB::statement("CREATE UNIQUE INDEX agent_configs_global_unique ON agent_configs (scope) WHERE scope = 'global'");
        }

        Schema::table('agent_configs', function (Blueprint $table): void {
            $table->dropColumn(['name', 'active']);
        });
    }
};
