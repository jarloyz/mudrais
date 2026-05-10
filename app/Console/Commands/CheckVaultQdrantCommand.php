<?php

namespace App\Console\Commands;

use App\Domains\Narrative\Models\Avatar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckVaultQdrantCommand extends Command
{
    protected $signature = 'vault:check-qdrant {vault_id}';
    protected $description = 'Muestra los puntos Qdrant de un vault y verifica si existen en DB';

    public function handle(): int
    {
        $vaultId = $this->argument('vault_id');
        $host    = config('services.qdrant.host', 'localhost');
        $port    = config('services.qdrant.port', '6333');
        $apiKey  = config('services.qdrant.api_key', '');
        $url     = "http://{$host}:{$port}/collections/matchmaking_hub/points/scroll";

        $headers  = array_filter(['api-key' => $apiKey]);
        $response = Http::withHeaders($headers)->post($url, [
            'filter' => [
                'must' => [
                    ['key' => 'guild_ids',   'match' => ['value' => $vaultId]],
                    ['key' => 'entity_type', 'match' => ['value' => 'avatar']],
                ],
            ],
            'with_payload' => true,
            'with_vector'  => false,
            'limit'        => 50,
        ]);

        $points = $response->json('result.points') ?? [];
        $this->info("Puntos en Qdrant: " . count($points));

        if (empty($points)) {
            $this->warn('No hay puntos en Qdrant para este vault.');
            return static::SUCCESS;
        }

        $rows = [];
        foreach ($points as $p) {
            $qdrantId = $p['id'] ?? '?';
            $avatarId = $p['payload']['avatar_id'] ?? 'MISSING';
            $inDb     = Avatar::find($avatarId) ? 'SI' : 'NO';
            $rows[]   = [$qdrantId, $avatarId, $inDb];
        }

        $this->table(['Qdrant ID', 'Avatar ID', 'En DB'], $rows);

        return static::SUCCESS;
    }
}
