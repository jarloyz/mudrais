<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class QdrantRebuildCommand extends Command
{
    protected $signature = 'qdrant:rebuild {--fresh : Whether to drop and recreate collections first}';

    protected $description = 'Orchestrates the complete sequential rebuild of Qdrant indexes (setup, tags, hub)';

    public function handle(): int
    {
        $this->info("Starting complete Qdrant rebuild process...");
        $this->newLine();

        if ($this->option('fresh')) {
            $this->info("Step 1: Recreating Qdrant schema...");
            $exitCode = Artisan::call('qdrant:setup', ['--fresh' => true], $this->output);
            if ($exitCode !== self::SUCCESS) {
                $this->error("qdrant:setup failed. Aborting rebuild.");
                return self::FAILURE;
            }
            $this->newLine();
        } else {
            $this->info("Step 1: Skipping Qdrant schema recreation (no --fresh flag provided).");
            $this->newLine();
        }

        $this->info("Step 2: Reindexing taxonomy tags...");
        $exitCode = Artisan::call('tags:reindex', ['--fresh' => true], $this->output);
        if ($exitCode !== self::SUCCESS) {
            $this->warn("tags:reindex reported some failures, but we will continue the rebuild.");
        }
        $this->newLine();

        $this->info("Step 3: Reindexing hub entities sequentially...");
        $exitCode = Artisan::call('hub:reindex', [
            '--force' => true,
            '--sync'  => true
        ], $this->output);

        if ($exitCode !== self::SUCCESS) {
            $this->error("hub:reindex failed.");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✓ Qdrant complete sequential rebuild finished successfully!");

        return self::SUCCESS;
    }
}
