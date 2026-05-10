<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\LoreRepository;
use App\Application\Services\QdrantService;
use App\Models\LoreEntry;
use Illuminate\Support\Facades\DB;

class QdrantLoreRepository implements LoreRepository
{
    public function __construct(
        private readonly QdrantService $qdrantService
    ) {}

    public function searchSimilar(string $vaultId, array $vector, int $limit = 5, array $filters = [], ?string $queryText = null): array
    {
        $intimacy = $filters['intimacy'] ?? 0;

        $contents = $this->qdrantService->searchWithFilters(
            queryVector: $vector,
            vaultId: $vaultId,
            currentIntimacy: $intimacy,
            limit: $limit,
            queryText: $queryText,
        );

        return array_map(fn($content) => [
            'content' => $content,
            'metadata' => [], // QdrantService actual no devuelve metadatos en searchWithFilters, solo contenido
            'distance' => 0.0 // Distancia no expuesta en el wrapper actual
        ], $contents);
    }

    public function addEntry(string $vaultId, string $content, array $vector, array $metadata = []): void
    {
        DB::transaction(function() use ($vaultId, $content, $vector, $metadata) {
            $entry = LoreEntry::query()->create([
                'vault_id' => $vaultId,
                'content' => $content,
                'metadata' => $metadata,
            ]);

            $this->qdrantService->syncLoreEntry($entry, $vector);
        });
    }
}
