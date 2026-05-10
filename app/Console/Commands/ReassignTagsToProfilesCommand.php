<?php

namespace App\Console\Commands;

use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\Uuid;

/**
 * Migración one-shot: reasigna taggables de Player a PlayerArchetypeProfile.
 *
 * @deprecated Migración Fase 2 MUDRAIS V2 ya ejecutada. No debe correrse de nuevo.
 */
class ReassignTagsToProfilesCommand extends Command
{
    protected $signature   = 'mudrais:reassign-tags {--dry-run : Solo reporta, no escribe en BD}';
    protected $description = '[DEPRECATED] Migración one-shot Fase 2 MUDRAIS V2 — ya ejecutada.';

    // Todos los valores posibles de taggable_type para Player (alias y clase base)
    private const PLAYER_MORPH_TYPES = [
        'App\\Models\\Player',
        'App\\Domains\\Community\\Models\\Player',
    ];

    private const PROFILE_MORPH_TYPE = 'App\\Domains\\Matchmaking\\Models\\PlayerArchetypeProfile';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        Log::info('[ReassignTagsToProfilesCommand] Inicio', ['dry_run' => $isDryRun]);
        $this->info($isDryRun ? '--- DRY RUN: no se escribirá en BD ---' : '--- MODO REAL: escribiendo en BD ---');

        $total    = DB::table('taggables')->whereIn('taggable_type', self::PLAYER_MORPH_TYPES)->count();
        $updated  = 0;
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        $this->info("Registros de taggables a migrar: {$total}");

        DB::table('taggables')
            ->whereIn('taggable_type', self::PLAYER_MORPH_TYPES)
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($isDryRun, &$updated, &$inserted, &$skipped, &$errors) {
                foreach ($rows as $row) {
                    try {
                        $this->processRow($row, $isDryRun, $updated, $inserted, $skipped);
                    } catch (\Throwable $e) {
                        $errors++;
                        Log::error('[ReassignTagsToProfilesCommand] Error procesando fila', [
                            'taggable_id'  => $row->taggable_id,
                            'taggable_type'=> $row->taggable_type,
                            'error'        => $e->getMessage(),
                        ]);
                        $this->error("  ERROR taggable_id={$row->taggable_id}: {$e->getMessage()}");
                    }
                }
            });

        $this->table(
            ['Actualizados', 'Insertados (copia)', 'Sin perfil (skip)', 'Errores'],
            [[$updated, $inserted, $skipped, $errors]]
        );

        Log::info('[ReassignTagsToProfilesCommand] Finalizado', compact('updated', 'inserted', 'skipped', 'errors'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processRow(
        object $row,
        bool $isDryRun,
        int &$updated,
        int &$inserted,
        int &$skipped,
    ): void {
        // Recuperar discord_id del player usando el taggable_id (= players.id)
        $player = DB::table('players')->where('id', $row->taggable_id)->first(['id', 'discord_id']);

        if (! $player) {
            Log::warning('[ReassignTagsToProfilesCommand] Player no encontrado para taggable', [
                'taggable_id' => $row->taggable_id,
            ]);
            $skipped++;
            return;
        }

        // Encontrar todos los perfiles del jugador
        $profiles = PlayerArchetypeProfile::where('discord_user_id', $player->discord_id)
            ->get(['id']);

        if ($profiles->isEmpty()) {
            Log::warning('[ReassignTagsToProfilesCommand] Sin perfiles para player, taggable no migrado', [
                'player_id'  => $player->id,
                'discord_id' => $player->discord_id,
            ]);
            $skipped++;
            return;
        }

        Log::debug('[ReassignTagsToProfilesCommand] Migrando taggable', [
            'taggable_id'   => $row->taggable_id,
            'profiles_count'=> $profiles->count(),
            'tag_context'   => $row->tag_context,
            'dry_run'       => $isDryRun,
        ]);

        $firstProfile = true;

        foreach ($profiles as $profile) {
            // Verificar si ya existe este tag+perfil+contexto para evitar violar UNIQUE constraint
            $alreadyExists = DB::table('taggables')
                ->where('canonical_tag_id', $row->canonical_tag_id)
                ->where('taggable_id', $profile->id)
                ->where('taggable_type', self::PROFILE_MORPH_TYPE)
                ->where('tag_context', $row->tag_context)
                ->exists();

            if ($alreadyExists) {
                Log::debug('[ReassignTagsToProfilesCommand] Registro ya existe, skip', [
                    'profile_id'      => $profile->id,
                    'canonical_tag_id'=> $row->canonical_tag_id,
                ]);
                $skipped++;
                continue;
            }

            if ($isDryRun) {
                $action = $firstProfile ? 'UPDATE' : 'INSERT';
                $this->line("  [dry-run] {$action} taggable id={$row->id} → profile_id={$profile->id}");
                $firstProfile ? $updated++ : $inserted++;
                $firstProfile = false;
                continue;
            }

            if ($firstProfile) {
                // Actualizar la fila original en lugar de borrar+insertar para preservar created_at
                DB::table('taggables')->where('id', $row->id)->update([
                    'taggable_id'   => $profile->id,
                    'taggable_type' => self::PROFILE_MORPH_TYPE,
                    'updated_at'    => now(),
                ]);
                $updated++;
            } else {
                // Duplicar el registro para perfiles adicionales
                DB::table('taggables')->insert([
                    'id'               => (string) Uuid::v7(),
                    'canonical_tag_id' => $row->canonical_tag_id,
                    'taggable_id'      => $profile->id,
                    'taggable_type'    => self::PROFILE_MORPH_TYPE,
                    'tag_context'      => $row->tag_context,
                    'original_text'    => $row->original_text ?? null,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $inserted++;
            }

            $firstProfile = false;
        }
    }
}
