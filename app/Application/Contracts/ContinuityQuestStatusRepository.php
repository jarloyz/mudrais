<?php

namespace App\Application\Contracts;

interface ContinuityQuestStatusRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForSceneContext(string $continuityId, string $vaultId): array;

    /**
     * @param array<string, mixed> $directive
     * @return array<string, mixed>
     */
    public function applyDirective(string $continuityId, ?string $sceneId, array $directive): array;

    /**
     * @return array<string, mixed>
     */
    public function getTransitionContext(string $continuityId, string $questId): array;
}
