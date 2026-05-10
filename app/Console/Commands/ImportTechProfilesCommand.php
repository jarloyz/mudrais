<?php

namespace App\Console\Commands;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildMember;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Narrative\Models\Vault;
use App\Jobs\Discord\ProcessRegistroStep2Job;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Importa perfiles técnicos desde JSON creando el Player y delegando
 * todo el pipeline de registro al ProcessRegistroStep2Job.
 *
 * Formato JSON: name, github_url, portfolio_url, red_lines[], yellow_lines[], preferences[], style
 *
 * Uso:
 *   sail artisan tech:import-profiles
 *   sail artisan tech:import-profiles --file=/ruta/custom.json --vault=<uuid|name> --guild=<discord_guild_id> --limit=5 --dry-run
 */
class ImportTechProfilesCommand extends Command
{
    protected $signature = 'tech:import-profiles
                            {--file= : Ruta al JSON (default: database/seeders/test_tech_profiles.json)}
                            {--vault= : UUID o nombre del Vault donde asociar los players}
                            {--guild= : discord_guild_id del guild donde registrar los players}
                            {--limit=0 : Límite de entradas a procesar (0 = todas)}
                            {--dry-run : Muestra el primer registro sin guardar ni despachar jobs}';

    protected $description = 'Crea players desde JSON de perfiles técnicos y delega el registro a ProcessRegistroStep2Job';

    public function handle(): int
    {
        Log::info('[ImportTechProfilesCommand] Inicio', [
            'dry_run' => $this->option('dry-run'),
            'limit'   => $this->option('limit'),
            'vault'   => $this->option('vault'),
            'guild'   => $this->option('guild'),
        ]);

        $archetype = Archetype::where('qdrant_vector_name', 'team_matcher')->first();
        if (! $archetype) {
            $this->error('Archetype "team_matcher" no encontrado. Ejecuta TechContextSeeder primero.');
            return self::FAILURE;
        }

        $vault = $this->resolveVault();
        $guild = $this->resolveGuild();

        $file = $this->option('file') ?: database_path('seeders/test_tech_profiles.json');
        if (! file_exists($file)) {
            $this->error("Archivo no encontrado: {$file}");
            return self::FAILURE;
        }

        $entries = json_decode(file_get_contents($file), true);
        if (! is_array($entries) || empty($entries)) {
            $this->error('JSON vacío o inválido.');
            return self::FAILURE;
        }

        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $total = $dispatched = $skipped = 0;

        $bar = $this->output->createProgressBar(min(count($entries), $limit > 0 ? $limit : PHP_INT_MAX));
        $bar->start();

        foreach ($entries as $index => $entry) {
            if ($limit > 0 && $total >= $limit) {
                break;
            }

            if (empty($entry['name'])) {
                $this->warn("\nEntrada #{$index} sin campo 'name'. Saltando...");
                continue;
            }

            $discordId = 'mock_tech_' . Str::slug($entry['name']);
            $username  = Str::slug($entry['name'], '_');
            $name      = $entry['name'];

            // ProcessRegistroStep2Job espera strings separados por coma, igual que Discord
            $data = [
                'github_url'    => $entry['github_url']    ?? '',
                'portfolio_url' => $entry['portfolio_url'] ?? '',
                'red_lines'     => implode(', ', $entry['red_lines']    ?? []),
                'yellow_lines'  => implode(', ', $entry['yellow_lines'] ?? []),
                'preferences'   => implode(', ', $entry['preferences']  ?? []),
                'style'         => $entry['style'] ?? '',
            ];

            $total++;
            $bar->advance();

            if ($dryRun) {
                $bar->clear();
                $this->info("\n[DRY-RUN] Player que se crearía:");
                $this->line("  discord_id:    {$discordId}");
                $this->line("  username:      {$username}");
                $this->line("  name:          {$name}");
                $this->line("  archetype:     {$archetype->name} ({$archetype->id})");
                $this->line("  vault:         " . ($vault ? "{$vault->name} ({$vault->id})" : '(ninguno)'));
                $this->line("  guild:         " . ($guild ? "{$guild->name} ({$guild->discord_guild_id})" : '(ninguno)'));
                $this->info("\n[DRY-RUN] Data que recibiría ProcessRegistroStep2Job:");
                foreach ($data as $k => $v) {
                    $this->line("  {$k}: " . Str::limit($v, 70));
                }
                $this->line("  → ProcessRegistroStep2Job despachado tras crear el player.");
                break;
            }

            try {
                $player = $this->createPlayer($discordId, $username, $name, $guild, $vault);

                Cache::put("registro_archetype_{$discordId}", $archetype->id, now()->addHours(2));

                ProcessRegistroStep2Job::dispatch(
                    discordId: $discordId,
                    data:      $data,
                    token:     'mock_import_' . $discordId,
                    guildId:   $guild?->discord_guild_id,
                    username:  $username,
                );

                Log::info('[ImportTechProfilesCommand] Player creado y job despachado', [
                    'discord_id'   => $discordId,
                    'player_id'    => $player->id,
                    'archetype_id' => $archetype->id,
                ]);

                $dispatched++;
            } catch (\Throwable $e) {
                $this->error("\nError en '{$name}': {$e->getMessage()}");
                Log::error('[ImportTechProfilesCommand] Error procesando entry', [
                    'discord_id' => $discordId,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);
                $skipped++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        if (! $dryRun) {
            $this->table(
                ['Total leídas', 'Jobs despachados', 'Errores'],
                [[$total, $dispatched, $skipped]]
            );
            $this->info('Workers necesarios para procesar el pipeline completo:');
            $this->line('   ./vendor/bin/sail artisan queue:work --queue=default');
            $this->line('   ./vendor/bin/sail artisan queue:work --queue=tags');
            $this->line('   ./vendor/bin/sail artisan queue:work --queue=index');
        }

        Log::info('[ImportTechProfilesCommand] Finalizado', compact('total', 'dispatched', 'skipped', 'dryRun'));

        return self::SUCCESS;
    }

    private function createPlayer(string $discordId, string $username, string $name, ?Guild $guild, ?Vault $vault): Player
    {
        $player = Player::updateOrCreate(
            ['discord_id' => $discordId],
            ['username' => $username, 'name' => $name]
        );

        if ($guild) {
            GuildMember::firstOrCreate(
                ['player_id' => $player->id, 'guild_id' => $guild->id],
                ['role' => 'player']
            );
        }

        if ($vault) {
            DB::table('vault_player_memberships')->updateOrInsert(
                ['vault_id' => $vault->id, 'player_id' => $player->id],
                ['role' => 'member', 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $player;
    }

    private function resolveVault(): ?Vault
    {
        $vaultOption = $this->option('vault');
        if (! $vaultOption) {
            $this->warn('--vault no especificado. Los players no se asociarán a ningún Vault.');
            return null;
        }

        $vault = Str::isUuid($vaultOption)
            ? Vault::find($vaultOption)
            : Vault::where('name', $vaultOption)->first();

        if (! $vault) {
            $this->warn("Vault '{$vaultOption}' no encontrado. Continuando sin vault.");
            Log::warning('[ImportTechProfilesCommand] Vault no encontrado', ['vault' => $vaultOption]);
            return null;
        }

        $this->info("Vault resuelto: {$vault->name} ({$vault->id})");
        return $vault;
    }

    private function resolveGuild(): ?Guild
    {
        $discordGuildId = $this->option('guild');
        if (! $discordGuildId) {
            $this->warn('--guild no especificado. Los players no se asociarán a ningún guild de Discord.');
            return null;
        }

        $guild = Guild::where('discord_guild_id', $discordGuildId)->first();
        if (! $guild) {
            $this->warn("Guild con discord_guild_id={$discordGuildId} no encontrado. Continuando sin guild.");
            Log::warning('[ImportTechProfilesCommand] Guild no encontrado', ['discord_guild_id' => $discordGuildId]);
            return null;
        }

        $this->info("Guild resuelto: {$guild->name} ({$guild->id})");
        return $guild;
    }
}
