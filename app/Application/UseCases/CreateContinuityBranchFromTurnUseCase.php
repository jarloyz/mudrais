<?php

namespace App\Application\UseCases;

use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class CreateContinuityBranchFromTurnUseCase
{
    public function __construct(
        private ContinuityRepository $continuityRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $parentContinuityId, string $newContinuityId, string $sceneId, int $turnIndex, ?string $label = null): array
    {
        $parentContinuityId = trim($parentContinuityId);
        $newContinuityId = trim($newContinuityId);
        $sceneId = trim($sceneId);
        $label = trim((string) $label);

        if ($parentContinuityId === '' || $newContinuityId === '' || $sceneId === '') {
            throw new InvalidArgumentException('parentContinuityId, newContinuityId y sceneId son requeridos');
        }
        if ($turnIndex <= 0) {
            throw new InvalidArgumentException('turnIndex invalido');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'create_continuity_branch_from_turn',
            'parentContinuityId' => $parentContinuityId,
            'continuityId' => $newContinuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
        ]);

        $logger->info('Inicio de branch desde turno');

        $parent = $this->continuityRepository->requireById($parentContinuityId);
        if (($parent['status'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException("no se puede ramificar una continuidad inactiva: {$parentContinuityId}");
        }

        $commit = $this->continuityRepository->requireCommitByTurn([
            'continuityId' => $parentContinuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
        ]);

        $created = $this->continuityRepository->createBranchFromCommit([
            'newContinuityId' => $newContinuityId,
            'parentContinuityId' => $parentContinuityId,
            'sceneId' => $sceneId,
            'commitId' => $commit['id'],
            'label' => $label !== '' ? $label : "{$newContinuityId} (from turn {$turnIndex})",
        ]);

        $result = [
            'continuityId' => $created['id'],
            'parentContinuityId' => $created['parent_id'],
            'rootContinuityId' => $created['root_id'],
            'sceneId' => $sceneId,
            'sourceTurnIndex' => $turnIndex,
            'sourceCommitId' => $commit['id'],
            'headCommitId' => $created['head_commit_id'] ?? null,
            'label' => $created['label'],
            'status' => $created['status'],
        ];

        $logger->info('Branch desde turno creado', [
            'sourceCommitId' => $result['sourceCommitId'],
            'headCommitId' => $result['headCommitId'],
        ]);

        return $result;
    }
}
