<?php

namespace App\Console\Commands;

use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Script de migración one-shot: copia datos contextuales de la tabla `players`
 * a `player_archetype_profiles` sin eliminar las columnas originales.
 *
 * @deprecated Migración Fase 1 MUDRAIS V2 ya ejecutada. No debe correrse de nuevo.
 */
class MigratePlayerDataToArchetypeProfilesCommand extends Command
{
    protected $signature   = 'mudrais:migrate-player-profiles {--dry-run : Solo reporta, no escribe en BD}';
    protected $description = '[DEPRECATED] Migración one-shot Fase 1 MUDRAIS V2 — ya ejecutada.';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        Log::info('[MigratePlayerDataToArchetypeProfilesCommand] Inicio', ['dry_run' => $isDryRun]);
        $this->info($isDryRun ? '--- DRY RUN: no se escribirá en BD ---' : '--- MODO REAL: escribiendo en BD ---');

        $defaultArchetype = Archetype::where('qdrant_vector_name', 'ttrpg_text_v1')->first();

        if (! $defaultArchetype) {
            $this->error('Arquetipo por defecto (ttrpg_text_v1) no encontrado. Ejecuta los seeders primero.');
            Log::error('[MigratePlayerDataToArchetypeProfilesCommand] Arquetipo default no existe.');
            return self::FAILURE;
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = 0;

        Player::with([
            'guildMembers', // HasMany on guild_members via player_id
        ])->cursor()->each(function (Player $player) use (
            $isDryRun, $defaultArchetype, &$created, &$updated, &$skipped, &$errors
        ) {
            Log::debug('[MigratePlayerDataToArchetypeProfilesCommand] Procesando player', [
                'player_id'  => $player->id,
                'discord_id' => $player->discord_id,
            ]);

            // No hay datos útiles que migrar si el player nunca completó el registro
            if (
                empty($player->affinities) &&
                empty($player->red_lines) &&
                $player->raw_profile === null &&
                $player->narrative_style_text === null
            ) {
                Log::debug('[MigratePlayerDataToArchetypeProfilesCommand] Sin datos para migrar, skip', [
                    'player_id' => $player->id,
                ]);
                $skipped++;
                return;
            }

            // Determinar los arquetipos del jugador vía sus guilds
            $archetypeIds = $this->resolveArchetypeIds($player, $defaultArchetype);

            foreach ($archetypeIds as $archetypeId) {
                try {
                    $payload = [
                        'positive_prefs'   => $player->affinities        ?? [],
                        'red_lines'        => $player->red_lines          ?? [],
                        'yellow_lines'     => $player->yellow_lines       ?? [],
                        'raw_profile'      => $player->raw_profile,
                        'preference_profile' => $player->narrative_style_text,
                        'schedule'         => $player->schedule           ?? [],
                        'schedule_raw'     => $player->schedule_raw,
                        'is_vectorized'    => $player->is_vectorized,
                        'metadata'         => [
                            'verbosity_level'  => $player->verbosity_level,
                            'experience_level' => $player->experience_level,
                            'nationality'      => $player->nationality,
                        ],
                    ];

                    Log::debug('[MigratePlayerDataToArchetypeProfilesCommand] Payload construido', [
                        'player_id'    => $player->id,
                        'archetype_id' => $archetypeId,
                        'dry_run'      => $isDryRun,
                    ]);

                    if ($isDryRun) {
                        $this->line("  [dry-run] Player {$player->discord_id} → archetype {$archetypeId}");
                        $created++;
                        return;
                    }

                    $exists = PlayerArchetypeProfile::where('discord_user_id', $player->discord_id)
                        ->where('archetype_id', $archetypeId)
                        ->exists();

                    PlayerArchetypeProfile::updateOrCreate(
                        ['discord_user_id' => $player->discord_id, 'archetype_id' => $archetypeId],
                        $payload
                    );

                    $exists ? $updated++ : $created++;

                    Log::info('[MigratePlayerDataToArchetypeProfilesCommand] Perfil migrado', [
                        'player_id'    => $player->id,
                        'archetype_id' => $archetypeId,
                        'action'       => $exists ? 'updated' : 'created',
                    ]);
                } catch (\Throwable $e) {
                    $errors++;
                    Log::error('[MigratePlayerDataToArchetypeProfilesCommand] Error al migrar perfil', [
                        'player_id'    => $player->id,
                        'archetype_id' => $archetypeId,
                        'error'        => $e->getMessage(),
                        'trace'        => $e->getTraceAsString(),
                    ]);
                    $this->error("  ERROR player {$player->discord_id}: {$e->getMessage()}");
                }
            }
        });

        $this->table(
            ['Creados', 'Actualizados', 'Sin datos (skip)', 'Errores'],
            [[$created, $updated, $skipped, $errors]]
        );

        Log::info('[MigratePlayerDataToArchetypeProfilesCommand] Finalizado', compact('created', 'updated', 'skipped', 'errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Devuelve los IDs de arquetipo asociados al player vía sus guilds.
     * Si no tiene guild o ninguna guild tiene arquetipo resuelto, usa el arquetipo default.
     *
     * @return list<int>
     */
    private function resolveArchetypeIds(Player $player, Archetype $default): array
    {
        // guild_members.guild_id contiene el discord_guild_id (snowflake)
        $discordGuildIds = $player->guildMembers->pluck('guild_id')->unique()->values()->toArray();

        if (empty($discordGuildIds)) {
            Log::debug('[MigratePlayerDataToArchetypeProfilesCommand] Sin guilds, usando default', [
                'player_id'            => $player->id,
                'default_archetype_id' => $default->id,
            ]);
            return [$default->id];
        }

        $archetypeIds = DB::table('guilds')
            ->whereIn('discord_guild_id', $discordGuildIds)
            ->whereNotNull('archetype_id')
            ->pluck('archetype_id')
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (empty($archetypeIds)) {
            Log::debug('[MigratePlayerDataToArchetypeProfilesCommand] Guilds sin arquetipo, usando default', [
                'player_id'       => $player->id,
                'discord_guild_ids' => $discordGuildIds,
            ]);
            return [$default->id];
        }

        return $archetypeIds;
    }
}
