<?php

namespace App\Application\UseCases;

use App\Application\Contracts\AgentGateway;
use App\Application\Contracts\QaLoopRunner;
use App\Application\Contracts\SceneCacheRepository;
use App\Application\Contracts\SceneContextBuilder;
use App\Application\Contracts\SceneRepository;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\V2SceneTypeResolver;
use App\Support\SimpleChatMemoryManager;
use RuntimeException;

final readonly class GenerateSceneTurnUseCase
{
    public function __construct(
        private SceneRepository $sceneRepository,
        private SceneContextBuilder $sceneContextBuilder,
        private SceneCacheRepository $sceneCacheRepository,
        private AgentGateway $agentGateway,
        private QaLoopRunner $qaLoopRunner,
        private StructuredLogger $logger,
        private SimpleChatMemoryManager $simpleChatMemoryManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        string $sceneId,
        string $userMessage,
        string $mode = 'write_scene',
        bool $apply = true,
        ?string $userId = null,
        ?callable $onChunk = null,
        ?array $qaLoop = null,
        ?callable $onProgress = null,
    ): array {
        $scene = $this->sceneRepository->findById($sceneId);
        if (! $scene) {
            throw new RuntimeException("sceneId no encontrado: {$sceneId}");
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'generate_scene_turn',
            'sceneId' => $sceneId,
            'userId' => $userId,
            'mode' => $mode,
        ]);

        $logger->info('Inicio de generacion de escena');

        if ($onProgress) {
            $onProgress('thinking', 'Construyendo contexto de escena y memorias...');
        }

        $context = $this->sceneContextBuilder->build($sceneId);
        $context['continuity_id'] = null;
        $context = $this->simpleChatMemoryManager->enrichContext($sceneId, $scene, $context);
        $normalizedQaLoop = $this->normalizeQaLoop($qaLoop);
        $sceneTypeMeta = V2SceneTypeResolver::resolveSceneType($scene, $context);
        $effectiveSceneType = $sceneTypeMeta['sceneType'];
        $effectiveMode = $mode === 'rewrite_scene' ? 'rewrite_scene' : 'write_scene';

        $sceneContextPacket = [
            'scene_id' => $scene->id,
            'scene_type' => $effectiveSceneType,
            'scene_type_reasons' => $sceneTypeMeta['reasons'],
            'characters' => $context['characters'] ?? [],
            'location' => $context['location'] ?? null,
            'objective' => $context['objective'] ?? null,
            'constraints' => $context['constraints'] ?? null,
            'draft' => $context['draft'] ?? null,
        ];
        $this->sceneCacheRepository->put('scene_context', $sceneId, $sceneContextPacket);

        $generated = $this->agentGateway->generateSceneTurn(
            $scene,
            $context,
            $userMessage,
            $effectiveMode,
            $effectiveSceneType,
            $normalizedQaLoop['enabled'] ? null : $onChunk,
            $userId,
        );
        $outputMd = $generated['outputMd'];
        $notes = $generated['notes'];
        $stateChanges = $generated['stateChanges'];

        if ($onProgress && ! empty($stateChanges)) {
            $onProgress('mutations', ['changes' => $stateChanges]);
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
                scene: $scene,
                context: $context,
                userMessage: $userMessage,
                mode: $effectiveMode,
                outputMd: $outputMd,
                qaLoop: $normalizedQaLoop,
                userId: $userId,
            );
            $outputMd = (string) ($qaLoopResult['outputMd'] ?? $outputMd);
        }

        $this->sceneCacheRepository->put('evidence_summary', $sceneId, [
            'scene_key' => $sceneId,
            'evidence_summary' => $this->buildEvidenceSummary($context),
        ]);

        if ($apply) {
            $updatedScene = new Activity(
                id: $scene->id,
                vaultId: $scene->vaultId,
                title: $scene->title,
                chapter: $scene->chapter,
                sceneNumber: $scene->sceneNumber,
                status: $scene->status,
                locationId: $scene->locationId,
                objective: $scene->objective,
                constraints: $scene->constraints,
                draft: $effectiveMode === 'rewrite_scene'
                    ? $outputMd
                    : trim((string) $scene->draft)."\n\n".$outputMd,
                characters: $scene->characters,
            );
            $this->sceneRepository->save($updatedScene);
        }

        $result = [
            'applied' => $apply,
            'mode' => $effectiveMode,
            'sceneId' => $sceneId,
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
        ];

        $logger->info('Generacion de escena completada', [
            'sceneType' => $effectiveSceneType,
            'applied' => $apply,
            'outputChars' => mb_strlen($outputMd),
            'qaLoopEnabled' => $normalizedQaLoop['enabled'],
            'qaLoopStatus' => $qaLoopResult['status'] ?? 'disabled',
            'qaLoopPasses' => $qaLoopResult['passes'] ?? 0,
        ]);

        return $result;
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

    private function buildEvidenceSummary(array $context): string
    {
        $parts = [];
        if (! empty($context['location']['name'])) {
            $parts[] = '- locacion: '.$context['location']['name'];
        }
        foreach (array_slice($context['characters'] ?? [], 0, 6) as $character) {
            $parts[] = '- personaje: '.($character['name'] ?? $character['id'] ?? 'sin_nombre');
        }

        return implode("\n", $parts);
    }
}
