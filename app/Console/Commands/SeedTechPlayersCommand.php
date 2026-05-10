<?php

namespace App\Console\Commands;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildMember;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Enums\IndexingStatus;
use App\Jobs\IndexPlayerStyleJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Importa jugadores de tech matchmaking desde un CSV y los indexa en matchmaking_hub.
 *
 * Equivalente de SeedTestPlayersCommand para el sistema MUDRAIS V2 (PlayerArchetypeProfile).
 *
 * Formato CSV requerido (encabezado obligatorio):
 *   discord_id, username, tech_stack, domains, experience_summary
 *
 * Uso:
 *   sail artisan tech:seed-players
 *   sail artisan tech:seed-players --file=/ruta/custom.csv --guild=discord_guild_id --limit=5 --dry-run
 */
class SeedTechPlayersCommand extends Command
{
    protected $signature = 'tech:seed-players
                            {--file= : Ruta al CSV (default: database/seeders/tech_players.csv)}
                            {--guild= : discord_guild_id del guild donde registrar los players}
                            {--limit=0 : Límite de filas a procesar (0 = todas)}
                            {--dry-run : Muestra el primer registro sin guardar ni despachar jobs}';

    protected $description = 'Crea PlayerArchetypeProfile para tech matchmaking desde CSV y los encola en IndexPlayerStyleJob';

    public function handle(): int
    {
        Log::info('[SeedTechPlayersCommand] Inicio', [
            'dry_run' => $this->option('dry-run'),
            'limit'   => $this->option('limit'),
            'guild'   => $this->option('guild'),
        ]);

        $archetype = Archetype::where('qdrant_vector_name', 'tech_v1')->first();
        if (! $archetype) {
            $this->error('Archetype "Tecnología" no encontrado. Ejecuta ArchetypeSeeder primero.');
            return self::FAILURE;
        }

        $guild = $this->resolveGuild();

        $file = $this->option('file') ?: database_path('seeders/tech_players.csv');
        if (! file_exists($file)) {
            $this->error("Archivo no encontrado: {$file}");
            return self::FAILURE;
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            $this->error('CSV vacío o inválido.');
            fclose($handle);
            return self::FAILURE;
        }

        $required = ['discord_id', 'username', 'tech_stack', 'domains'];
        $missing  = array_diff($required, $header);
        if ($missing) {
            $this->error('Columnas requeridas faltantes: ' . implode(', ', $missing));
            fclose($handle);
            return self::FAILURE;
        }

        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $total = $created = $skipped = 0;

        $bar = $this->output->createProgressBar();
        $bar->start();

        while (($row = fgetcsv($handle)) !== false) {
            if ($limit > 0 && $total >= $limit) {
                break;
            }

            if (count($header) !== count($row)) {
                $this->warn("\nFila con columnas incorrectas. Saltando...");
                continue;
            }

            $data = array_combine($header, $row);
            $total++;
            $bar->advance();

            if ($dryRun) {
                $bar->clear();
                $this->info("\n[DRY-RUN] Registro que se crearía:");
                $this->line("  discord_id:          {$data['discord_id']}");
                $this->line("  username:            {$data['username']}");
                $this->line("  tech_stack:          {$data['tech_stack']}");
                $this->line("  domains:             {$data['domains']}");
                $this->line("  experience_summary:  " . ($data['experience_summary'] ?? '(vacío)'));
                $this->line("  archetype:           {$archetype->name} ({$archetype->qdrant_vector_name})");
                $this->line("  guild:               " . ($guild ? "{$guild->name} ({$guild->id})" : '(ninguno)'));
                $this->line("  → IndexPlayerStyleJob sería despachado tras guardar.");
                break;
            }

            try {
                $profile = DB::transaction(function () use ($data, $archetype, $guild) {
                    $player = Player::updateOrCreate(
                        ['discord_id' => $data['discord_id']],
                        ['username'   => $data['username']]
                    );

                    if ($guild) {
                        GuildMember::firstOrCreate(
                            ['player_id' => $player->id, 'guild_id' => $guild->id],
                            ['role' => 'player']
                        );
                    }

                    return PlayerArchetypeProfile::updateOrCreate(
                        [
                            'player_id'    => $player->id,
                            'archetype_id' => $archetype->id,
                        ],
                        [
                            'discord_user_id' => $data['discord_id'],
                            'positive_prefs'  => [],
                            'content_raw'     => [
                                'tech_stack'          => $data['tech_stack'] ?: null,
                                'domains'             => $data['domains'] ?: null,
                                'experience_summary'  => ($data['experience_summary'] ?? '') ?: null,
                            ],
                            'is_vectorized'   => false,
                            'is_available'    => true,
                            'indexing_status' => IndexingStatus::Pending,
                        ]
                    );
                });

                IndexPlayerStyleJob::dispatch($profile->id);

                Log::info('[SeedTechPlayersCommand] Profile creado y job despachado', [
                    'discord_id' => $data['discord_id'],
                    'profile_id' => $profile->id,
                ]);

                $created++;
            } catch (\Throwable $e) {
                $this->error("\nError en {$data['discord_id']}: {$e->getMessage()}");
                Log::error('[SeedTechPlayersCommand] Error procesando player', [
                    'discord_id' => $data['discord_id'],
                    'error'      => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        fclose($handle);
        $bar->finish();
        $this->newLine(2);

        if (! $dryRun) {
            $this->table(
                ['Total leídas', 'Profiles creados', 'Errores'],
                [[$total, $created, $skipped]]
            );
            $this->info("Recuerda correr los workers para procesar los embeddings:");
            $this->line("   ./vendor/bin/sail artisan queue:work --queue=index");
        }

        Log::info('[SeedTechPlayersCommand] Finalizado', compact('total', 'created', 'skipped', 'dryRun'));

        return self::SUCCESS;
    }

    private function resolveGuild(): ?Guild
    {
        $discordGuildId = $this->option('guild');
        if (! $discordGuildId) {
            $this->warn('--guild no especificado. Los players no se asociarán a ningún guild en Qdrant.');
            return null;
        }

        $guild = Guild::where('discord_guild_id', $discordGuildId)->first();
        if (! $guild) {
            $this->warn("Guild con discord_guild_id={$discordGuildId} no encontrado. Continuando sin guild.");
            Log::warning('[SeedTechPlayersCommand] Guild no encontrado', ['discord_guild_id' => $discordGuildId]);
            return null;
        }

        $this->info("Guild resuelto: {$guild->name} ({$guild->id})");
        return $guild;
    }
}
