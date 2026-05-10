<?php

namespace App\Application\UseCases;

use App\Application\Contracts\ContinuityQuestStatusRepository;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class ApplyQuestProgressDirectiveUseCase
{
    public function __construct(
        private ContinuityQuestStatusRepository $continuityQuestStatusRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $directive
     * @return array<string, mixed>
     */
    public function execute(string $continuityId, ?string $sceneId, array $directive, ?int $turnIndex = null): array
    {
        $continuityId = trim($continuityId);
        $sceneId = $sceneId !== null ? trim($sceneId) : null;

        if ($continuityId === '') {
            throw new InvalidArgumentException('continuityId es requerido');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'apply_quest_progress_directive',
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
            'questId' => trim((string) ($directive['quest_id'] ?? '')) ?: null,
        ]);

        $logger->info('Inicio de aplicacion de directiva de quest', [
            'matched' => (bool) ($directive['matched'] ?? false),
            'advanceStep' => (bool) ($directive['advance_step'] ?? false),
            'newStageNumber' => $directive['new_stage_number'] ?? null,
            'newStatus' => $directive['new_status'] ?? null,
        ]);

        if (! (bool) ($directive['matched'] ?? false)) {
            $logger->info('Directiva de quest omitida por falta de match');

            return [
                'applied' => false,
                'reason' => 'not_matched',
            ];
        }

        if (! (bool) ($directive['advance_step'] ?? false) && trim((string) ($directive['new_status'] ?? '')) === '') {
            $logger->info('Directiva de quest omitida porque no hay cambio persistible');

            return [
                'applied' => false,
                'reason' => 'no_persistible_change',
                'warnings' => [],
            ];
        }

        $questId = trim((string) ($directive['quest_id'] ?? ''));
        $transitionContext = $this->continuityQuestStatusRepository->getTransitionContext($continuityId, $questId);
        $validation = $this->validateDirective($directive, $transitionContext);
        if (! $validation['valid']) {
            $logger->warning('Directiva de quest rechazada por validacion', [
                'reason' => $validation['reason'],
                'warnings' => $validation['warnings'],
                'transitionContext' => $transitionContext,
            ]);

            return [
                'applied' => false,
                'reason' => $validation['reason'],
                'warnings' => $validation['warnings'],
            ];
        }

        if ($validation['normalizedDirective'] !== $directive) {
            $directive = $validation['normalizedDirective'];
            $logger->warning('Directiva de quest normalizada antes de persistir', [
                'warnings' => $validation['warnings'],
                'normalizedDirective' => $directive,
            ]);
        }

        $result = $this->continuityQuestStatusRepository->applyDirective($continuityId, $sceneId, $directive);
        $result['warnings'] = $validation['warnings'];

        $logger->info('Directiva de quest aplicada', $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $directive
     * @param array<string, mixed> $transitionContext
     * @return array{valid:bool,reason:string|null,warnings:array<int,string>,normalizedDirective:array<string,mixed>}
     */
    private function validateDirective(array $directive, array $transitionContext): array
    {
        $warnings = [];
        $questExists = (bool) ($transitionContext['questExists'] ?? false);
        $currentStatus = trim((string) ($transitionContext['currentStatus'] ?? ''));
        $currentStageNumber = is_numeric($transitionContext['currentStageNumber'] ?? null)
            ? (int) $transitionContext['currentStageNumber']
            : null;
        $nextStageNumber = is_numeric($transitionContext['nextStageNumber'] ?? null)
            ? (int) $transitionContext['nextStageNumber']
            : null;
        $lastStageNumber = is_numeric($transitionContext['lastStageNumber'] ?? null)
            ? (int) $transitionContext['lastStageNumber']
            : null;
        $validStageNumbers = array_values(array_map(
            static fn (mixed $stage): int => (int) $stage,
            is_array($transitionContext['validStageNumbers'] ?? null) ? $transitionContext['validStageNumbers'] : []
        ));

        if (! $questExists) {
            return [
                'valid' => false,
                'reason' => 'quest_not_found',
                'warnings' => ['La directiva referencia una quest inexistente.'],
                'normalizedDirective' => $directive,
            ];
        }

        $normalizedDirective = $directive;
        $advanceStep = (bool) ($directive['advance_step'] ?? false);
        $newStatus = trim((string) ($directive['new_status'] ?? ''));
        $newStageNumber = is_numeric($directive['new_stage_number'] ?? null)
            ? (int) $directive['new_stage_number']
            : null;

        if ($newStatus !== '' && ! in_array($newStatus, ['active', 'completed', 'failed', 'hidden'], true)) {
            return [
                'valid' => false,
                'reason' => 'invalid_status',
                'warnings' => ["Estado de quest no permitido: {$newStatus}."],
                'normalizedDirective' => $directive,
            ];
        }

        if (in_array($currentStatus, ['completed', 'failed'], true) && $newStatus === 'active') {
            return [
                'valid' => false,
                'reason' => 'terminal_status_locked',
                'warnings' => ['No se puede reabrir una quest ya marcada como terminal sin un flujo explicito de reactivacion.'],
                'normalizedDirective' => $directive,
            ];
        }

        if ($advanceStep) {
            if ($newStageNumber === null) {
                if ($nextStageNumber === null) {
                    return [
                        'valid' => false,
                        'reason' => 'no_more_stages',
                        'warnings' => ['No hay mas etapas definidas para avanzar en esta quest.'],
                        'normalizedDirective' => $directive,
                    ];
                }

                $newStageNumber = $nextStageNumber;
                $normalizedDirective['new_stage_number'] = $newStageNumber;
                $warnings[] = "Se auto-selecciono la etapa {$newStageNumber} como siguiente paso.";
            }

            if ($validStageNumbers !== [] && ! in_array($newStageNumber, $validStageNumbers, true)) {
                return [
                    'valid' => false,
                    'reason' => 'invalid_stage_number',
                    'warnings' => ["La etapa {$newStageNumber} no existe en el arbol de la quest."],
                    'normalizedDirective' => $directive,
                ];
            }

            if ($newStatus === '' || $newStatus === 'active') {
                if ($nextStageNumber !== null && $newStageNumber !== $nextStageNumber) {
                    return [
                        'valid' => false,
                        'reason' => 'invalid_stage_jump',
                        'warnings' => ["Solo se permite avanzar a la siguiente etapa valida ({$nextStageNumber})."],
                        'normalizedDirective' => $directive,
                    ];
                }

                if ($currentStageNumber !== null && $newStageNumber <= $currentStageNumber) {
                    return [
                        'valid' => false,
                        'reason' => 'non_advancing_stage',
                        'warnings' => ['La nueva etapa no representa un avance real sobre el estado actual.'],
                        'normalizedDirective' => $directive,
                    ];
                }
            }
        }

        if ($newStatus === 'completed') {
            if ($validStageNumbers !== [] && $newStageNumber === null) {
                $normalizedDirective['new_stage_number'] = $lastStageNumber;
                $warnings[] = 'Se normalizo la quest completada a la ultima etapa definida.';
            } elseif ($validStageNumbers !== [] && $newStageNumber !== null && $newStageNumber !== $lastStageNumber) {
                return [
                    'valid' => false,
                    'reason' => 'completed_requires_last_stage',
                    'warnings' => ["Una quest completada debe cerrar en la ultima etapa definida ({$lastStageNumber})."],
                    'normalizedDirective' => $directive,
                ];
            }
        }

        if ($newStatus === 'failed') {
            if ($newStageNumber !== null && $currentStageNumber !== null && $newStageNumber !== $currentStageNumber) {
                return [
                    'valid' => false,
                    'reason' => 'failed_requires_current_stage',
                    'warnings' => ['Una quest fallida no debe saltar de etapa al mismo tiempo que cambia a failed.'],
                    'normalizedDirective' => $directive,
                ];
            }

            if ($newStageNumber === null && $currentStageNumber !== null) {
                $normalizedDirective['new_stage_number'] = $currentStageNumber;
                $warnings[] = 'Se preservo la etapa actual al marcar la quest como failed.';
            }
        }

        return [
            'valid' => true,
            'reason' => null,
            'warnings' => $warnings,
            'normalizedDirective' => $normalizedDirective,
        ];
    }
}
