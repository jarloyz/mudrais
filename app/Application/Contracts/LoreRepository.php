<?php

namespace App\Application\Contracts;

interface LoreRepository
{
    /**
     * @param string $vaultId
     * @param string $content
     * @param array<int, float> $vector
     * @param array<string, mixed> $metadata
     * @return void
     */
    public function addEntry(string $vaultId, string $content, array $vector, array $metadata = []): void;

    /**
     * @param string $vaultId
     * @param array<int, float> $vector
     * @param int $limit
     * @param array<string, mixed> $filters
     * @param string|null $queryText Texto original de búsqueda, para trazabilidad en qdrant_logs
     * @return array<int, array{content:string, metadata:array<string,mixed>, distance:float}>
     */
    public function searchSimilar(string $vaultId, array $vector, int $limit = 5, array $filters = [], ?string $queryText = null): array;
}
