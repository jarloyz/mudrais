<?php

namespace App\Console\Commands;

use App\Application\Services\VectorRetrievalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LoreIngestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lore:ingest {vault_id} {file?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Carga masiva de conocimiento (Lore) desde un archivo JSON hacia el almacenamiento vectorial (Qdrant)';

    /**
     * Execute the console command.
     */
    public function handle(VectorRetrievalService $vectorService): int
    {
        $vaultId = $this->argument('vault_id');
        $file = $this->argument('file') ?? 'lore.json';

        if (!File::exists($file)) {
            $this->error("El archivo no existe: {$file}");
            return static::FAILURE;
        }

        $this->info("Iniciando ingesta de lore para el vault: {$vaultId} (Backend: Qdrant)");
        $this->info("Leyendo archivo: {$file}");

        $json = File::get($file);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Error decodificando JSON: " . json_last_error_msg());
            return static::FAILURE;
        }

        if (!is_array($data)) {
            $this->error("El archivo JSON debe contener un arreglo de entradas.");
            return static::FAILURE;
        }

        $count = count($data);
        $this->info("Se encontraron {$count} entradas. Procesando...");

        $bar = $this->output->createProgressBar($count);

        $success = 0;
        $failed = 0;

        foreach ($data as $entry) {
            $content = $entry['content'] ?? null;
            $metadata = $entry['metadata'] ?? [];

            if (empty($content) || !is_string($content)) {
                $this->error("\nEntrada inválida encontrada (sin campo 'content'). Omitiendo.");
                $failed++;
                $bar->advance();
                continue;
            }

            // Normalizar metadatos para estructura de LoreEntry / Qdrant Payload
            $normalizedMetadata = array_merge([
                'tags' => [],
                'requirements' => [
                    'intimacy_min' => 0,
                    'wealth_min' => 0,
                    'influence_min' => 0,
                    'required_quest_flag' => null,
                ]
            ], $metadata);

            try {
                $vectorService->addEntry($vaultId, $content, $normalizedMetadata);
                $success++;
            } catch (\Exception $e) {
                $this->error("\nError procesando entrada: " . $e->getMessage());
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Ingesta completada.");
        $this->info("Exitosos: {$success}");
        if ($failed > 0) {
            $this->error("Fallidos: {$failed}");
            return static::FAILURE;
        }

        return static::SUCCESS;
    }
}
