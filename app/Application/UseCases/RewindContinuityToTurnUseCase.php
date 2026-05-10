<?php

namespace App\Application\UseCases;

use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class RewindContinuityToTurnUseCase
{
    public function __construct(
        private ContinuityRepository $continuityRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $continuityId, string $sceneId, int $turnIndex): array
    {
        $continuityId = trim($continuityId);
        $sceneId = trim($sceneId);

        if ($continuityId === '' || $sceneId === '') {
            throw new InvalidArgumentException('continuityId y sceneId son requeridos');
        }
        if ($turnIndex <= 0) {
            throw new InvalidArgumentException('turnIndex invalido');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'rewind_continuity_to_turn',
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
        ]);

        $logger->info('Inicio de rewind de continuidad');

        $continuity = $this->continuityRepository->requireById($continuityId);
        if (($continuity['status'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException("continuidad no activa: {$continuityId}");
        }

        $commit = $this->continuityRepository->requireCommitByTurn([
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
        ]);

        $sceneState = $this->continuityRepository->checkoutCommit([
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'commitId' => $commit['id'],
        ]);

        $result = [
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
            'commitId' => $commit['id'],
            'restored' => true,
            'sceneState' => $sceneState,
        ];

        $logger->info('Rewind de continuidad completado', [
            'commitId' => $commit['id'],
        ]);

        return $result;
    }
}
