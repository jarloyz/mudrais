<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Sin implementación (TODO). Pendiente de diseño del ImportVaultUseCase.
 */
class BundleVaultCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'historia:bundle {path} {--out=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Empaqueta un Vault en formato JSON para depuración o exportación';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');
        $out = $this->option('out');

        Log::channel('pipeline')->info('BundleVaultCommand started', ['path' => $path, 'out' => $out]);

        $this->info("Empaquetando Vault desde {$path}...");

        // TODO: Llamar al UseCase correspondiente

        $this->info("Vault empaquetado.");

        Log::channel('pipeline')->info('BundleVaultCommand finished successfully');

        return Command::SUCCESS;
    }
}
