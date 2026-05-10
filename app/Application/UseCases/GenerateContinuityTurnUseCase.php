<?php

namespace App\Application\UseCases;

use App\Application\Agents\QuestAgent;
use App\Application\Agents\LibrarianAgent;
use App\Application\Services\VectorRetrievalService;
use App\Application\Contracts\AgentGateway;
use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\EventEngineRepository;
use App\Application\Contracts\QaLoopRunner;
use App\Application\Contracts\SceneContextBuilder;
use App\Application\Contracts\SceneRepository;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\V2SceneTypeResolver;
use RuntimeException;

final readonly class GenerateContinuityTurnUseCase
{
    public function __construct(
        private SceneRepository $sceneRepository,
        private SceneContextBuilder $sceneContextBuilder,
        private ContinuityRepository $continuityRepository,
        private ApplyCharacterRuntimeStatusUseCase $applyCharacterRuntimeStatusUseCase,
        private AgentGateway $agentGateway,
        private QaLoopRunner $qaLoopRunner,
        private StructuredLogger $logger,
        private ?\App\Application\Agents\QuestAgent $questAgent = null,
        private ?\App\Application\Agents\LibrarianAgent $librarianAgent = null,
        private ?\App\Application\Services\VectorRetrievalService $vectorRetrievalService = null,
        private ?ApplyQuestProgressDirectiveUseCase $applyQuestProgressDirectiveUseCase = null,
        private ?EventEngineRepository $eventEngineRepository = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        string $continuityId,
        string $sceneId,
        string $userMessage,
        string $mode = 'write_scene',
        bool $apply = true,
        ?string $userId = null,
        ?callable $onChunk = null,
        ?array $qaLoop = null,
        ?callable $onProgress = null,
    ): array {
        $continuity = $this->continuityRepository->requireById($continuityId);
        if (($continuity['status'] ?? 'active') !== 'active') {
            throw new RuntimeException("continuidad no activa: {$continuityId}");
        }

        $baseScene = $this->sceneRepository->findById($sceneId);
        if (! $baseScene) {
            throw new RuntimeException("sceneId no encontrado: {$sceneId}");
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'generate_continuity_turn',
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'userId' => $userId,
            'mode' => $mode,
        ]);
        $logger->info('Inicio de generacion de turno de continuidad');

        if ($onProgress) {
            $onProgress('thinking', 'Construyendo contexto de escena y memorias...');
        }

        $this->continuityRepository->ensureSceneStateFromBase([
            'continuityId' => $continuityId,
            'scene' => $baseScene,
        ]);
        $sceneState = $this->continuityRepository->requireSceneState([
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
        ]);

        $effectiveScene = new Activity(
            id: $baseScene->id,
            vaultId: $baseScene->vaultId,
            title: $baseScene->title,
            chapter: $baseScene->chapter,
            sceneNumber: $baseScene->sceneNumber,
            status: $baseScene->status,
            locationId: $baseScene->locationId,
            objective: $sceneState['objective'] ?? $baseScene->objective,
            constraints: $sceneState['constraints'] ?? $baseScene->constraints,
            draft: $sceneState['draft'] ?? $baseScene->draft,
            characters: $baseScene->characters,
        );

        $context = $this->sceneContextBuilder->build($sceneId, $continuityId, $userId);
        $context['continuity_id'] = $continuityId;

        // Librarian / Router Phase
        $loreNotes = [];
        if ($this->librarianAgent && $this->vectorRetrievalService) {
            if ($onProgress) {
                $onProgress('thinking', 'Librarian analizando si se necesita lore...');
            }
            $librarianEval = $this->librarianAgent->evaluate($effectiveScene, $context, $userMessage, $userId);
            if ($librarianEval['needs_search']) {
                if ($onProgress) {
                    $onProgress('searching', "Buscando en lore: {$librarianEval['query']}");
                }
                $logger->info('Búsqueda de lore disparada por Librarian', ['query' => $librarianEval['query']]);

                // Extraer nivel de intimidad para búsqueda RAG filtrada
                $intimacy = 0;
                $controlledChar = collect($context['characters'] ?? [])->first(fn($c) => ($c['controlled_by_player_id'] ?? $c['controlled_by_user_id'] ?? null) == $userId);
                if ($controlledChar) {
                    $intimacy = collect($controlledChar['profile']['runtimeStatus'] ?? [])
                        ->where('status_key', 'intimacy_level')
                        ->first()['status_value'] ?? 0;
                }

                $loreNotes = $this->vectorRetrievalService->search($effectiveScene->vaultId, $librarianEval['query'], 5, ['intimacy' => (int)$intimacy]);
            }
        }
        $context['lore_notes'] = $loreNotes;

        if ($onProgress && $this->questAgent) {
            $onProgress('thinking', 'QuestAgent evaluando directivas de misión...');
        }
        $questDirective = $this->questAgent?->evaluate($effectiveScene, $context, $userMessage, $userId) ?? null;
        if (is_array($questDirective)) {
            $context['questDirective'] = $questDirective;
        }
        $normalizedQaLoop = $this->normalizeQaLoop($qaLoop);
        if ($onProgress) {
            $onProgress('thinking', 'Resolviendo tipo de escena y cargando reglas del Writer...');
        }
        $sceneTypeMeta = V2SceneTypeResolver::resolveSceneType($effectiveScene, $context);
        $effectiveSceneType = $sceneTypeMeta['sceneType'];
        $effectiveMode = $mode === 'rewrite_scene' ? 'rewrite_scene' : 'write_scene';

        $generated = $this->agentGateway->generateSceneTurn(
            $effectiveScene,
            $context,
            $userMessage,
            $effectiveMode,
            $effectiveSceneType,
            $normalizedQaLoop['enabled'] ? null : $onChunk,
            $userId,
        );

        if ($onProgress && ! empty($generated['stateChanges'])) {
            $onProgress('mutations', ['changes' => $generated['stateChanges']]);
        }

        $outputMd = trim((string) ($generated['outputMd'] ?? ''));
        if ($outputMd === '') {
            throw new RuntimeException('agentGateway no devolvio outputMd');
        }

        $qaLoopResult = [
            'enabled' => false,
            'triggered' => false,
            'passes' => 0,
            'highestSeverity' => 'none',
            'status' => 'disabled',
            'issues' => [],
            'outputMd' => $outputMd,
        ];

        if ($effectiveSceneType === 'simple') {
            $qaLoopResult = $this->qaLoopRunner->run(
                scene: $effectiveScene,
                context: $context,
                userMessage: $userMessage,
                mode: $effectiveMode,
                outputMd: $outputMd,
                qaLoop: $normalizedQaLoop,
                userId: $userId,
            );
            $outputMd = trim((string) ($qaLoopResult['outputMd'] ?? $outputMd));
        }

        $notes = array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            is_array($generated['notes'] ?? null) ? $generated['notes'] : []
        )));
        if (is_array($questDirective) && trim((string) ($questDirective['directive_for_writer'] ?? '')) !== '') {
            $notes[] = 'quest_directive: '.trim((string) $questDirective['directive_for_writer']);
        }
        $stateChanges = is_array($generated['stateChanges'] ?? null) ? $generated['stateChanges'] : [];

        $turnIndex = null;
        $commitId = null;
        $characterStatusUpdateSummary = null;
        $questUpdateSummary = null;
        $eventTriggers = null;

        if ($apply) {
            $currentHead = $this->continuityRepository->getHeadCommit([
                'continuityId' => $continuityId,
                'sceneId' => $sceneId,
            ]);

            if ($effectiveMode === 'rewrite_scene') {
                $this->continuityRepository->replaceSceneDraft([
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'draftMd' => $outputMd,
                ]);
            } else {
                $this->continuityRepository->appendSceneDraft([
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'additionMd' => $outputMd,
                ]);
            }

            $turnIndex = $this->continuityRepository->nextTurnIndex([
                'continuityId' => $continuityId,
                'sceneId' => $sceneId,
            ]);

            $this->continuityRepository->appendTurn([
                'continuityId' => $continuityId,
                'sceneId' => $sceneId,
                'turnIndex' => $turnIndex,
                'mode' => $effectiveMode,
                'userMessage' => $userMessage,
                'outputMd' => $outputMd,
                'notes' => $notes,
            ]);

            if ($stateChanges !== []) {
                $this->continuityRepository->appendStateChanges([
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'changes' => $stateChanges,
                ]);
            }

            $characterStatusUpdateSummary = $this->applyCharacterRuntimeStatusUseCase->execute(
                continuityId: $continuityId,
                sceneId: $sceneId,
                userId: $userId,
                stateChanges: $stateChanges,
                characterContext: is_array($context['characters'] ?? null) ? $context['characters'] : [],
                turnIndex: $turnIndex,
            );

            if ($this->applyQuestProgressDirectiveUseCase && is_array($questDirective)) {
                $questUpdateSummary = $this->applyQuestProgressDirectiveUseCase->execute(
                    continuityId: $continuityId,
                    sceneId: $sceneId,
                    directive: $questDirective,
                    turnIndex: $turnIndex,
                );
            }

            if ($this->eventEngineRepository && $effectiveSceneType === 'complex') {
                $eventTriggers = $this->eventEngineRepository->evaluateAndApplyTriggers([
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'locationId' => $baseScene->locationId ?? '',
                    'turnIndex' => $turnIndex,
                    'characterIds' => array_values(array_filter(array_map(
                        static fn ($character): string => trim((string) ($character['id'] ?? '')),
                        is_array($context['characters'] ?? null) ? $context['characters'] : []
                    ))),
                    'tags' => [],
                ]);
            }

            $commit = $this->continuityRepository->createCommitFromCurrentState([
                'continuityId' => $continuityId,
                'sceneId' => $sceneId,
                'parentCommitId' => $currentHead['id'] ?? null,
                'turnIndex' => $turnIndex,
                'mode' => $effectiveMode,
                'message' => 'turn '.$turnIndex.': '.mb_substr($userMessage, 0, 120),
            ]);
            $commitId = $commit['id'] ?? null;
        }

        $logger->info('Turno de continuidad completado', [
            'sceneType' => $effectiveSceneType,
            'turnIndex' => $turnIndex,
            'commitId' => $commitId,
            'applied' => $apply,
        ]);

        return [
            'applied' => $apply,
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
            'commitId' => $commitId,
            'mode' => $effectiveMode,
            'sceneType' => $effectiveSceneType,
            'outputMd' => $outputMd,
            'notes' => $notes,
            'stateChanges' => $stateChanges,
            'qaLoop' => [
                'enabled' => (bool) ($qaLoopResult['enabled'] ?? false),
                'triggered' => (bool) ($qaLoopResult['triggered'] ?? false),
                'passes' => (int) ($qaLoopResult['passes'] ?? 0),
                'highestSeverity' => (string) ($qaLoopResult['highestSeverity'] ?? 'none'),
                'status' => (string) ($qaLoopResult['status'] ?? 'disabled'),
                'issues' => $qaLoopResult['issues'] ?? [],
            ],
            'characterStatusUpdateSummary' => $characterStatusUpdateSummary,
            'questDirective' => $questDirective,
            'questUpdateSummary' => $questUpdateSummary,
            'eventTriggers' => $eventTriggers,
        ];
    }

    /**
     * @param array<string, mixed>|null $qaLoop
     * @return array{enabled:bool,max_passes:int,min_severity:string}
     */
    private function normalizeQaLoop(?array $qaLoop): array
    {
        $minSeverity = trim((string) ($qaLoop['min_severity'] ?? 'medium'));

        return [
            'enabled' => (bool) ($qaLoop['enabled'] ?? false),
            'max_passes' => max(1, min(3, (int) ($qaLoop['max_passes'] ?? 1))),
            'min_severity' => in_array($minSeverity, ['minor', 'medium', 'major'], true) ? $minSeverity : 'medium',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function mockSearchLore(string $query, string $vaultId): array
    {
        return [
            "Resultado de búsqueda de lore para: '{$query}' (Vault: {$vaultId})",
            "Nota: El servicio de recuperación vectorial (sqlite-vec) aún está en fase de mock."
        ];
    }
}
