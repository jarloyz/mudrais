<?php

namespace App\Application\Contracts;

interface CharacterRuntimeStatusRepository
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, int>
     */
    public function upsertManyStatus(array $input): array;

    /**
     * @param array<int, string> $characterIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listForSceneContext(string $continuityId, ?string $sceneId, ?string $userId, array $characterIds): array;
}
