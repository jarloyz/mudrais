<?php

namespace App\Application\Services;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Contracts\LoreRepository;
use App\Application\Contracts\StructuredLogger;
use App\Support\UserAiSettingsResolver;

class VectorRetrievalService
{
    public function __construct(
        private readonly EmbeddingGateway $embeddingGateway,
        private readonly LoreRepository $loreRepository,
        private readonly StructuredLogger $logger,
        private readonly UserAiSettingsResolver $settingsResolver,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function search(string $vaultId, string $query, int $limit = 5, array $filters = []): array
    {
        $model = $this->settingsResolver->resolveAgentModel(null, 'embedding');

        $this->logger->info('VectorRetrievalService search started', [
            'vaultId' => $vaultId,
            'query' => $query,
            'model' => $model
        ]);

        $vector = [];
        try {
            $vector = $this->embeddingGateway->embed($model, $query);
        } catch (\Exception $e) {
            $this->logger->warning('VectorRetrievalService embeddings failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }

        if (empty($vector)) {
            return [];
        }

        $results = $this->loreRepository->searchSimilar($vaultId, $vector, $limit, $filters, $query);

        return array_map(fn($r) => $r['content'], $results);
    }

    public function addEntry(string $vaultId, string $content, array $metadata = []): void
    {
        $model = $this->settingsResolver->resolveAgentModel(null, 'embedding');
        try {
            $vector = $this->embeddingGateway->embed($model, $content);
            if (!empty($vector)) {
                $this->loreRepository->addEntry($vaultId, $content, $vector, $metadata);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to add lore entry due to embedding error', ['error' => $e->getMessage()]);
        }
    }
}
