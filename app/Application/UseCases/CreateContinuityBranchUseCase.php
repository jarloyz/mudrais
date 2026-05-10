<?php

namespace App\Application\UseCases;

use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class CreateContinuityBranchUseCase
{
    public function __construct(
        private ContinuityRepository $continuityRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $parentContinuityId, string $newContinuityId, ?string $label = null): array
    {
        $parentContinuityId = trim($parentContinuityId);
        $newContinuityId = trim($newContinuityId);
        $label = trim((string) $label);

        if ($parentContinuityId === '') {
            throw new InvalidArgumentException('parentContinuityId es requerido');
        }
        if ($newContinuityId === '') {
            throw new InvalidArgumentException('newContinuityId es requerido');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'create_continuity_branch',
            'parentContinuityId' => $parentContinuityId,
            'continuityId' => $newContinuityId,
        ]);

        $logger->info('Inicio de creacion de branch de continuidad');

        $parent = $this->continuityRepository->requireById($parentContinuityId);
        if (($parent['status'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException("no se puede ramificar una continuidad inactiva: {$parentContinuityId}");
        }

        $created = $this->continuityRepository->createBranch([
            'newContinuityId' => $newContinuityId,
            'parentContinuityId' => $parentContinuityId,
            'label' => $label !== '' ? $label : "{$newContinuityId} (branch)",
        ]);

        $result = [
            'continuityId' => $created['id'],
            'parentContinuityId' => $created['parent_id'],
            'rootContinuityId' => $created['root_id'],
            'label' => $created['label'],
            'status' => $created['status'],
        ];

        $logger->info('Branch de continuidad creado', [
            'rootContinuityId' => $result['rootContinuityId'],
            'status' => $result['status'],
        ]);

        return $result;
    }
}
