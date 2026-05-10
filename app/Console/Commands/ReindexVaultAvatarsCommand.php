<?php

namespace App\Console\Commands;

use App\Application\Services\QdrantService;
use App\Domains\Narrative\Models\Avatar;
use App\Jobs\IndexAvatarJob;
use Illuminate\Console\Command;

class ReindexVaultAvatarsCommand extends Command
{
    protected $signature = 'vault:reindex-avatars {vault_id}';
    protected $description = 'Limpia Qdrant y re-encola IndexAvatarJob para todos los avatares de un vault';

    public function handle(QdrantService $qdrant): int
    {
        $vaultId = $this->argument('vault_id');
        $avatars = Avatar::where('vault_id', $vaultId)->get();

        if ($avatars->isEmpty()) {
            $this->error("No se encontraron avatares para vault {$vaultId}");
            return static::FAILURE;
        }

        $this->info("Procesando {$avatars->count()} avatares...");
        $bar = $this->output->createProgressBar($avatars->count());
        $bar->start();

        foreach ($avatars as $avatar) {
            if ($avatar->avatar_hub_qdrant_id) {
                $qdrant->deleteHubPoint($avatar->avatar_hub_qdrant_id);
            }

            $avatar->updateQuietly([
                'is_hub_indexed'        => false,
                'avatar_hub_qdrant_id'  => null,
                'avatar_context_vector' => null,
                'indexing_status'       => 'pending',
                'index_error'           => null,
            ]);

            IndexAvatarJob::dispatch($avatar->id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Listo: {$avatars->count()} avatares encolados.");

        return static::SUCCESS;
    }
}
