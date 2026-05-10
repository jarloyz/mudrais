<?php

namespace App\Console\Commands;

use App\Application\Services\QdrantService;
use Illuminate\Console\Command;

class DropObsoleteQdrantCollectionsCommand extends Command
{
    protected $signature = 'qdrant:drop-obsolete
                            {--force : Omitir confirmación interactiva}';

    protected $description = 'Elimina las colecciones Qdrant obsoletas que ya no usa el sistema (player_matchmaking)';

    private const OBSOLETE = ['player_matchmaking'];

    public function handle(QdrantService $qdrant): int
    {
        $this->warn('Colecciones a eliminar: ' . implode(', ', self::OBSOLETE));

        if (! $this->option('force') && ! $this->confirm('¿Continuar?', false)) {
            $this->info('Operación cancelada.');
            return self::SUCCESS;
        }

        foreach (self::OBSOLETE as $collection) {
            $ok = $qdrant->dropCollection($collection);
            $ok
                ? $this->info("  ✓ {$collection} eliminada.")
                : $this->error("  ✗ No se pudo eliminar {$collection} (puede que ya no exista).");
        }

        return self::SUCCESS;
    }
}
