<?php

namespace App\Console\Commands;

use App\Jobs\SeedMassivePlayerJob;
use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * @deprecated Usa el modelo Player legacy con el schema pre-MUDRAIS V2 y la colección
 *             players_profiles (eliminada). Reemplazado por SeedTechPlayersCommand
 *             para el nuevo sistema de PlayerArchetypeProfile + matchmaking_hub.
 */
class SeedMassiveQdrantPlayersCommand extends Command
{
    protected $signature = 'qdrant:seed-massive {--file= : Archivo CSV a procesar} {--skip-qdrant : Solo inserta en la tabla, sin enviar a Qdrant}';
    protected $description = '[DEPRECATED] Crea/actualiza jugadores usando el schema legacy (players_profiles). Ver SeedTechPlayersCommand para el nuevo sistema.';

    public function handle(): int
    {
        $file = $this->option('file') ?? database_path('seeders/massive_qdrant_players.csv');

        if (!file_exists($file)) {
            $this->error("Archivo no encontrado: {$file}");
            return Command::FAILURE;
        }

        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);
        if (!$headers) {
            $this->error("Archivo CSV vacío.");
            return Command::FAILURE;
        }

        $skipQdrant = $this->option('skip-qdrant');
        $mode = $skipQdrant ? 'solo DB (sin Qdrant)' : 'DB + Qdrant';
        $this->info("Iniciando carga en modo [{$mode}] desde {$file}...");

        $totalDispatched = 0;
        $bar = $this->output->createProgressBar();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            // 1. Cargar o actualizar jugador en la base de datos llenando todos los campos
            $player = DB::transaction(function () use ($data, $skipQdrant) {
                $redLines    = array_values(array_filter(array_map('trim', explode(',', $data['raw_red_lines']    ?? ''))));
                $yellowLines = array_values(array_filter(array_map('trim', explode(',', $data['raw_yellow_lines'] ?? ''))));
                $affinities  = array_values(array_filter(array_map('trim', explode(',', $data['raw_preferences']  ?? ''))));

                $schedule = [];
                if (!empty($data['schedule'])) {
                    $decoded = json_decode($data['schedule'], true);
                    $schedule = is_array($decoded) ? $decoded : [];
                }

                return Player::updateOrCreate(
                    ['discord_id' => $data['discord_id']],
                    [
                        'username'             => $data['username'],
                        'age'                  => $data['age'] ?: null,
                        'country_code'         => $data['country_code'] ?: null,
                        'nationality'          => ($data['nationality'] ?? '') ?: null,
                        'experience_level'     => ($data['experience_level'] ?? '') ?: null,
                        'verbosity_level'      => ($data['verbosity_level'] ?? '') ?: null,
                        'schedule_raw'         => ($data['schedule_raw'] ?? '') ?: null,
                        'schedule'             => $schedule,
                        'red_lines'            => $redLines,
                        'yellow_lines'         => $yellowLines,
                        'affinities'           => $affinities,
                        'narrative_style_text' => ($data['narrative_style'] ?? '') ?: null,
                        'raw_profile'          => ($data['text'] ?? '') ?: null,
                        'about_me'             => ($data['about_me'] ?? '') ?: null,
                        'is_vectorized'        => $skipQdrant,
                        'energy'               => 100,
                        'coin'                 => 0,
                        'elo'                  => 1000,
                        'last_active_at'       => now(),
                        'is_active'            => true,
                    ]
                );
            });

            if (!$skipQdrant) {
                // 2. Armar payload de Qdrant
                $payload = [
                    'entity_type'       => 'player',
                    'player_id'         => $player->id,
                    'experience_level'  => (int)$data['experience_level'],
                    'verbosity_level'   => (int)$data['verbosity_level'],
                    'red_lines_tags'    => array_filter(explode('|', $data['red_lines_tags']    ?? '')),
                    'yellow_lines_tags' => array_filter(explode('|', $data['yellow_lines_tags'] ?? '')),
                    'preferences_tags'  => array_filter(explode('|', $data['preferences_tags']  ?? '')),
                    'guild_ids'         => array_filter(explode('|', $data['guild_ids']          ?? '')),
                    'text'              => $data['text'],
                ];

                // 3. Despacha el trabajo enviando el modelo completo
                SeedMassivePlayerJob::dispatch($player, $payload);
            }

            $totalDispatched++;
            $bar->advance();
        }

        fclose($handle);
        $bar->finish();
        $this->newLine();
        if ($skipQdrant) {
            $this->info("¡Completado! {$totalDispatched} jugadores insertados/actualizados en BD (Qdrant omitido).");
        } else {
            $this->info("¡Completado! {$totalDispatched} jugadores insertados en BD y trabajos encolados.");
            $this->warn("Asegúrate de tener workers corriendo para procesar los embeddings, por ejemplo:");
            $this->line("   ./vendor/bin/sail artisan queue:work");
        }

        return Command::SUCCESS;
    }
}
