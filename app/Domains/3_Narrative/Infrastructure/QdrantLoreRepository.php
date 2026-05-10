<?php

namespace App\Domains\Narrative\Infrastructure;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Narrative\Contracts\LoreRepositoryInterface;
use App\Domains\Narrative\Models\LoreEntry;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class QdrantLoreRepository implements LoreRepositoryInterface
{
    public function __construct(
        private readonly QdrantService $qdrant,
        private readonly EmbeddingGateway $embeddingGateway,
        private readonly UserAiSettingsResolver $resolver,
    ) {
    }

    public function findByVaultId(string $vaultId, ?int $atVersion = null): array
    {
        Log::channel('qdrant')->debug('[QdrantLoreRepository@findByVaultId]', [
            'vault_id'   => $vaultId,
            'at_version' => $atVersion,
        ]);

        $query = LoreEntry::where('vault_id', $vaultId);

        if ($atVersion !== null) {
            $query->where('version_start', '<=', $atVersion)
                ->where(function ($q) use ($atVersion) {
                    $q->whereNull('version_end')
                        ->orWhere('version_end', '>=', $atVersion);
                });
        }

        return $query->get()->all();
    }

    public function save(LoreEntry $entry): void
    {
        $entry->save();
    }

    public function searchSemantic(string $query, string $vaultId, int $limit = 5): array
    {
        Log::channel('qdrant')->debug('[QdrantLoreRepository@searchSemantic]', [
            'vault_id' => $vaultId,
            'query'    => substr($query, 0, 50),
            'limit'    => $limit,
        ]);

        $embeddingModel = $this->resolver->resolveAgentModel(null, 'embedding');
        $vector = $this->embeddingGateway->embed($embeddingModel, $query);

        if (empty($vector)) {
            return [];
        }

        $results = $this->qdrant->searchLore($vector, ['vault_id' => $vaultId], $limit);

        return array_filter(array_map(function ($res) {
            $entry = LoreEntry::find($res['id'] ?? null);
            if (! $entry) {
                return null;
            }
            return ['entry' => $entry, 'score' => (float) ($res['score'] ?? 0)];
        }, $results));
    }
}
