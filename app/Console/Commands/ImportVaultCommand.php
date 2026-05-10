<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Sin implementación (TODO). Pendiente de diseño del ImportVaultUseCase.
 */
class ImportVaultCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'historia:import-vault {path} {--vault-id=} {--pnj-lib=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa un Vault en formato Markdown a la base de datos relacional';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');
        $vaultId = $this->option('vault-id');

        Log::channel('pipeline')->info('ImportVaultCommand started', ['path' => $path, 'vault_id' => $vaultId]);

        $this->info("Importando Vault desde {$path}...");

        // TODO: Llamar al UseCase ImportVaultUseCase

        $this->info("Vault importado correctamente.");

        Log::channel('pipeline')->info('ImportVaultCommand finished successfully');

        return Command::SUCCESS;
    }
}
