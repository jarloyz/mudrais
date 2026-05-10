<?php

namespace App\Jobs;

use App\Application\Services\QdrantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncPlayerQdrantGuildsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $playerId,
    ) {
        $this->onQueue('sync');
    }

    public function handle(QdrantService $qdrant): void
    {
        $guildIds = DB::table('guild_members')
            ->where('player_id', $this->playerId)
            ->pluck('guild_id')
            ->map(fn($id) => (string) $id)
            ->all();

        if (empty($guildIds)) {
            Log::warning("SyncPlayerQdrantGuildsJob: no guilds found for player {$this->playerId}.");
            return;
        }

        $success = $qdrant->updatePlayerPayload($this->playerId, ['guild_ids' => $guildIds]);

        Log::debug("SyncPlayerQdrantGuildsJob: guild_ids synced.", [
            'player_id' => $this->playerId,
            'guild_ids' => $guildIds,
            'success'   => $success,
        ]);

        if (! $success) {
            $this->fail(new \Exception("Qdrant payload update failed for player {$this->playerId}"));
        }
    }
}
