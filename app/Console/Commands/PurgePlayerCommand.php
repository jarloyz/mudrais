<?php

namespace App\Console\Commands;

use App\Application\Services\QdrantService;
use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgePlayerCommand extends Command
{
    protected $signature = 'player:purge
                            {player_id : ID numérico del player a eliminar}
                            {--force : Omitir confirmación}';

    protected $description = 'Elimina un player y todos sus datos asociados (DB + Qdrant)';

    public function handle(QdrantService $qdrant): int
    {
        $playerId = (int) $this->argument('player_id');

        Log::debug('[PurgePlayerCommand@handle] Inicio', ['player_id' => $playerId]);

        $player = Player::find($playerId);

        if (! $player) {
            Log::warning('[PurgePlayerCommand@handle] Player no encontrado', ['player_id' => $playerId]);
            $this->error("Player #{$playerId} no existe.");
            return self::FAILURE;
        }

        $this->warn("Se eliminará todo lo relacionado con el player:");
        $this->line("  · ID       : {$player->id}");
        $this->line("  · Username : {$player->username}");
        $this->line("  · Discord  : {$player->discord_id}");
        $this->line('');
        $this->line('  DB  → taggables (del player) + player (cascade: guild_members, vault_player_memberships, agent_configs, ...)');
        $this->line('  Qdrant → players_profiles (punto #{$player->id})');

        if (! $this->option('force') && ! $this->confirm('¿Continuar?', false)) {
            $this->info('Cancelado.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($player) {
            // Taggables no tiene FK a players (polimórfico), hay que borrar manualmente
            $deleted = DB::table('taggables')
                ->where('taggable_type', Player::class)
                ->where('taggable_id', $player->id)
                ->delete();

            Log::info('[PurgePlayerCommand@handle] taggables eliminados', [
                'player_id' => $player->id,
                'count'     => $deleted,
            ]);

            $this->info("  ✓ taggables: {$deleted} filas eliminadas");

            // Eliminar el player; el resto cae por CASCADE (guild_members, vault_player_memberships, agent_configs, etc.)
            $player->delete();

            Log::info('[PurgePlayerCommand@handle] Player eliminado de DB', ['player_id' => $player->id]);
            $this->info("  ✓ Player #{$player->id} ({$player->username}) eliminado de la DB (cascades aplicados)");
        });

        // Qdrant — fuera de la transacción (sistema externo)
        $ok = $qdrant->deletePlayerVector($player->id);

        if ($ok) {
            $this->info("  ✓ Vector Qdrant (players_profiles #{$player->id}) eliminado");
        } else {
            $this->warn("  · Vector Qdrant no encontrado o error al eliminar (puede que no estuviera vectorizado)");
        }

        Log::info('[PurgePlayerCommand@handle] Purga completada', [
            'player_id'    => $player->id,
            'qdrant_ok'    => $ok,
        ]);

        $this->info('');
        $this->info("Purga de player #{$player->id} completada.");

        return self::SUCCESS;
    }
}
