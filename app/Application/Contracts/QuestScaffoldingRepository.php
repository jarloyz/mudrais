<?php

namespace App\Application\Contracts;

interface QuestScaffoldingRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findForBootstrap(string $vaultId, string $questId): ?array;

    /**
     * @param array<string, mixed> $scaffold
     * @return array<string, mixed>
     */
    public function saveGeneratedQuest(string $vaultId, array $scaffold): array;
}
