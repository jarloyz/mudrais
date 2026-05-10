<?php

namespace App\Application\Contracts;

interface LegacyCanonImporter
{
    /**
     * @return array<string, int|string>
     */
    public function import(string $sourcePath, string $vaultId, string $scope = 'canon_base+scenes'): array;
}
