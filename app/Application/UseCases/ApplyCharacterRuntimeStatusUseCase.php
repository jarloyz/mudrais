<?php

namespace App\Application\UseCases;

use App\Application\Contracts\CharacterRuntimeStatusRepository;
use App\Application\Contracts\StructuredLogger;
use App\Application\Services\CharacterStatusMapper;
use InvalidArgumentException;

final readonly class ApplyCharacterRuntimeStatusUseCase
{
    public function __construct(
        private CharacterRuntimeStatusRepository $characterRuntimeStatusRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $stateChanges
     * @param array<int, array<string, mixed>> $characterContext
     * @return array<string, mixed>
     */
    public function execute(
        string $continuityId,
        ?string $sceneId,
        ?string $userId,
        array $stateChanges,
        array $characterContext,
        ?int $turnIndex = null,
    ): array {
        $continuityId = trim($continuityId);
        $sceneId = $sceneId !== null ? trim($sceneId) : null;

        if ($continuityId === '') {
            throw new InvalidArgumentException('continuityId es requerido');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'apply_character_runtime_status',
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'userId' => $userId,
            'turnIndex' => $turnIndex,
        ]);

        $logger->info('Inicio de actualizacion de runtime de personajes', [
            'stateChangeCount' => count($stateChanges),
            'characterCount' => count($characterContext),
        ]);

        $mapped = CharacterStatusMapper::mapStateChangesToCharacterStatus($stateChanges, $characterContext, 'system');

        $written = 0;
        if ($mapped['rows'] !== []) {
            $result = $this->characterRuntimeStatusRepository->upsertManyStatus([
                'continuityId' => $continuityId,
                'sceneId' => $sceneId,
                'userId' => $userId,
                'rows' => $mapped['rows'],
                'source' => 'system',
            ]);
            $written = (int) ($result['written'] ?? 0);
        }

        $logger->info('Actualizacion de runtime completada', [
            'appliedCount' => $written,
            'warningCount' => count($mapped['warnings']),
        ]);

        return [
            'appliedCount' => $written,
            'warnings' => $mapped['warnings'],
        ];
    }
}
