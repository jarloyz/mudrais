<?php

namespace App\Console\Commands;

use App\Application\Services\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetPlayersCommand extends Command
{
    protected $signature = 'reset:players {--force : Omitir confirmación}';

    protected $description = 'Limpia jugadores, tags canónicos y la colección players_profiles en Qdrant';

    public function handle(QdrantService $qdrant): int
    {
        $this->warn('Esto eliminará:');
        $this->line('  · Tabla players (todos los registros)');
        $this->line('  · Tabla taggables (todas las relaciones de tags)');
        $this->line('  · Tabla canonical_tags (todos los tags canónicos)');
        $this->line('  · Colección Qdrant: players_profiles');
        $this->line('  · Colección Qdrant: taxonomy_tags');

        if (! $this->option('force') && ! $this->confirm('¿Continuar?', false)) {
            $this->info('Cancelado.');
            return self::SUCCESS;
        }

        // DB — orden importa por FK
        DB::table('taggables')->delete();
        $this->info('✓ taggables limpiado');

        DB::table('canonical_tags')->delete();
        $this->info('✓ canonical_tags limpiado');

        DB::table('players')->delete();
        $this->info('✓ players limpiado');

        // Qdrant
        foreach (['players_profiles', 'taxonomy_tags'] as $collection) {
            $ok = $qdrant->dropCollection($collection);
            $this->info($ok ? "✓ Qdrant [{$collection}] eliminada" : "· Qdrant [{$collection}] no encontrada");
        }

        return self::SUCCESS;
    }
}
