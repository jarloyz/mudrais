<?php

namespace App\Console\Commands;

use App\Application\Services\PlayerRegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Usa PlayerRegistrationService que escribe al modelo Player legacy con campos
 *             pre-MUDRAIS V2 (narrative_style_text, experience_level, etc.). No crea
 *             PlayerArchetypeProfile. Ver SeedTechPlayersCommand para el nuevo sistema.
 */
class SeedTestPlayersCommand extends Command
{
    protected $signature = 'matchmaking:seed-players {--id= : Cargar un jugador específico por discord_id}';

    protected $description = '[DEPRECATED] Carga players via PlayerRegistrationService (schema legacy). Ver SeedTechPlayersCommand.';

    /**
     * Execute the console command.
     */
    public function handle(PlayerRegistrationService $registrationService)
    {
        $csvPath = database_path('seeders/test_players.csv');

        if (! file_exists($csvPath)) {
            $this->error("El archivo CSV no existe en la ruta: {$csvPath}");
            return Command::FAILURE;
        }

        $specificId = $this->option('id');

        $this->info("Leyendo archivo CSV: {$csvPath}");

        $file = fopen($csvPath, 'r');
        $header = fgetcsv($file);

        if (! $header) {
            $this->error("El archivo CSV está vacío o es inválido.");
            fclose($file);
            return Command::FAILURE;
        }

        $playersData = [];
        while (($row = fgetcsv($file)) !== false) {
            if (count($header) !== count($row)) {
                $this->warn("Fila con cantidad de columnas incorrecta. Saltando...");
                continue;
            }
            $data = array_combine($header, $row);

            if ($specificId && $data['discord_id'] !== $specificId) {
                continue;
            }

            $playersData[] = $data;
        }
        fclose($file);

        if (empty($playersData)) {
            $this->warn("No se encontraron registros para procesar.");
            return Command::SUCCESS;
        }

        $this->info(sprintf("Iniciando procesamiento de %d jugador(es)...", count($playersData)));
        $bar = $this->output->createProgressBar(count($playersData));

        foreach ($playersData as $data) {
            try {
                $registrationService->register($data);
                $bar->advance();
            } catch (\Exception $e) {
                $this->error("\nError procesando jugador {$data['discord_id']}: {$e->getMessage()}");
                Log::error("SeedTestPlayersCommand error: " . $e->getMessage(), [
                    'discord_id' => $data['discord_id'],
                    'exception' => $e
                ]);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("¡Proceso completado!");
        $this->info("Nota: Si usas colas asíncronas, recuerda correr './vendor/bin/sail artisan queue:work' para finalizar la traducción y vectorización en Qdrant.");

        return Command::SUCCESS;
    }
}
