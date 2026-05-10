<?php

namespace App\Domains\Narrative\Contracts;

use App\Domains\Narrative\Models\LoreEntry;

interface LoreRepositoryInterface
{
    /**
     * @return list<LoreEntry>
     */
    public function findByVaultId(string $vaultId, ?int $atVersion = null): array;

    public function save(LoreEntry $entry): void;

    /**
     * Busca lore semánticamente en Qdrant.
     *
     * @return list<array{entry: LoreEntry, score: float}>
     */
    public function searchSemantic(string $query, string $vaultId, int $limit = 5): array;
}
