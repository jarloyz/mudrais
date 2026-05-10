<?php

namespace App\Application\UseCases;

use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class CreateContinuityBranchFromCommitUseCase
{
    public function __construct(
        private ContinuityRepository $continuityRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $parentContinuityId, string $newContinuityId, string $sceneId, int $commitId, ?string $label = null): array
    {
        $parentContinuityId = trim($parentContinuityId);
        $newContinuityId = trim($newContinuityId);
        $sceneId = trim($sceneId);
        $label = trim((string) $label);

        if ($parentContinuityId === '' || $newContinuityId === '' || $sceneId === '') {
            throw new InvalidArgumentException('parentContinuityId, newContinuityId y sceneId son requeridos');
        }
        if ($commitId <= 0) {
            throw new InvalidArgumentException('commitId invalido');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'create_continuity_branch_from_commit',
            'parentContinuityId' => $parentContinuityId,
            'continuityId' => $newContinuityId,
            'sceneId' => $sceneId,
            'commitId' => $commitId,
        ]);

        $logger->info('Inicio de branch desde commit');

        $parent = $this->continuityRepository->requireById($parentContinuityId);
        if (($parent['status'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException("no se puede ramificar una continuidad inactiva: {$parentContinuityId}");
        }

        $commit = $this->continuityRepository->requireCommitById($commitId);
        if (($commit['continuity_id'] ?? null) !== $parentContinuityId) {
            throw new InvalidArgumentException("commit {$commitId} no pertenece a continuidad padre {$parentContinuityId}");
        }
        if (($commit['activity_id'] ?? null) !== $sceneId) {
            throw new InvalidArgumentException("commit {$commitId} no pertenece a escena {$sceneId}");
        }

        $created = $this->continuityRepository->createBranchFromCommit([
            'newContinuityId' => $newContinuityId,
            'parentContinuityId' => $parentContinuityId,
            'sceneId' => $sceneId,
            'commitId' => $commitId,
            'label' => $label !== '' ? $label : "{$newContinuityId} (from commit {$commitId})",
        ]);

        $result = [
            'continuityId' => $created['id'],
            'parentContinuityId' => $created['parent_id'],
            'rootContinuityId' => $created['root_id'],
            'sceneId' => $sceneId,
            'sourceCommitId' => $commitId,
            'headCommitId' => $created['head_commit_id'] ?? null,
            'label' => $created['label'],
            'status' => $created['status'],
        ];

        $logger->info('Branch desde commit creado', [
            'headCommitId' => $result['headCommitId'],
        ]);

        return $result;
    }
}
