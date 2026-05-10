<?php

namespace App\Application\UseCases;

use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class CheckoutContinuityCommitUseCase
{
    public function __construct(
        private ContinuityRepository $continuityRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $continuityId, string $sceneId, int $commitId): array
    {
        $continuityId = trim($continuityId);
        $sceneId = trim($sceneId);

        if ($continuityId === '') {
            throw new InvalidArgumentException('continuityId es requerido');
        }
        if ($sceneId === '') {
            throw new InvalidArgumentException('sceneId es requerido');
        }
        if ($commitId <= 0) {
            throw new InvalidArgumentException('commitId invalido');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'checkout_continuity_commit',
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'commitId' => $commitId,
        ]);

        $logger->info('Inicio de checkout de commit de continuidad');

        $continuity = $this->continuityRepository->requireById($continuityId);
        if (($continuity['status'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException("continuidad no activa: {$continuityId}");
        }

        $commit = $this->continuityRepository->requireCommitById($commitId);
        if (($commit['continuity_id'] ?? null) !== $continuityId) {
            throw new InvalidArgumentException("commit {$commitId} no pertenece a continuidad {$continuityId}");
        }
        if (($commit['activity_id'] ?? null) !== $sceneId) {
            throw new InvalidArgumentException("commit {$commitId} no pertenece a escena {$sceneId}");
        }

        $sceneState = $this->continuityRepository->checkoutCommit([
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'commitId' => $commitId,
        ]);

        $result = [
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'commitId' => $commitId,
            'restored' => true,
            'sceneState' => $sceneState,
        ];

        $logger->info('Checkout de continuidad completado', [
            'activeContinuityId' => $sceneState['continuity_id'] ?? null,
            'activeCommitId' => $sceneState['continuity_commit_id'] ?? null,
        ]);

        return $result;
    }
}
