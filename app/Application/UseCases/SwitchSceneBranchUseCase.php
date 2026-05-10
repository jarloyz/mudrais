<?php

namespace App\Application\UseCases;

use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class SwitchSceneBranchUseCase
{
    public function __construct(
        private ContinuityRepository $continuityRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $sceneId, string $continuityId): array
    {
        $sceneId = trim($sceneId);
        $continuityId = trim($continuityId);

        if ($sceneId === '' || $continuityId === '') {
            throw new InvalidArgumentException('sceneId y continuityId son requeridos');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'switch_scene_branch',
            'sceneId' => $sceneId,
            'continuityId' => $continuityId,
        ]);

        $logger->info('Inicio de switch de branch de escena');

        $continuity = $this->continuityRepository->requireById($continuityId);
        if (($continuity['status'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException("continuidad no activa: {$continuityId}");
        }

        $this->continuityRepository->setSceneActiveContinuity([
            'sceneId' => $sceneId,
            'continuityId' => $continuityId,
        ]);

        $active = $this->continuityRepository->getActiveSceneContinuity($sceneId);

        $result = [
            'sceneId' => $sceneId,
            'continuityId' => $active['continuity_id'] ?? $continuityId,
            'switched' => true,
        ];

        $logger->info('Switch de branch completado', [
            'activeCommitId' => $active['continuity_commit_id'] ?? null,
        ]);

        return $result;
    }
}
