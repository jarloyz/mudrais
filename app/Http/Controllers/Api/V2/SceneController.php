<?php

namespace App\Http\Controllers\Api\V2;

use App\Application\Contracts\StructuredLogger;
use App\Application\Services\CharacterSnapshotService;
use App\Application\Services\TimelineForkingService;
use App\Application\UseCases\CreateSceneBootstrapUseCase;
use App\Application\UseCases\GenerateSceneTurnUseCase;
use App\Application\UseCases\RefreshSimpleChatMemoryUseCase;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Models\CharacterRecord;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;
use App\Models\ContinuityCommit;
use App\Models\ContinuityStateChange;
use App\Models\ContinuityTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SceneController extends Controller
{
    public function store(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'scene_id' => ['required', 'string'],
            'vault_id' => ['nullable', 'string'],
            'title' => ['nullable', 'string'],
            'user_message' => ['required', 'string'],
            'mode' => ['nullable', 'string'],
            'apply' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer'],
            'qa_loop_enabled' => ['nullable', 'boolean'],
            'qa_loop_max_passes' => ['nullable', 'integer', 'min:1', 'max:3'],
            'qa_loop_min_severity' => ['nullable', 'string', 'in:minor,medium,major'],
        ]);

        $sceneId = trim((string) $payload['scene_id']);
        $scopedLogger = $logger->withContext([
            'layer' => 'http',
            'endpoint' => 'api.v2.scene.create',
            'sceneId' => $sceneId,
            'vaultId' => trim((string) ($payload['vault_id'] ?? '')),
        ]);

        try {
            [$sceneCreated, $sceneRecord] = $this->ensureSceneExists($payload, $scopedLogger);

            /** @var GenerateSceneTurnUseCase $generateSceneTurnUseCase */
            $generateSceneTurnUseCase = app(GenerateSceneTurnUseCase::class);
            $result = $generateSceneTurnUseCase->execute(
                sceneId: $sceneId,
                userMessage: $payload['user_message'],
                mode: (string) ($payload['mode'] ?? 'write_scene'),
                apply: (bool) ($payload['apply'] ?? true),
                userId: isset($payload['user_id']) ? (int) $payload['user_id'] : null,
                qaLoop: [
                    'enabled' => (bool) ($payload['qa_loop_enabled'] ?? false),
                    'max_passes' => (int) ($payload['qa_loop_max_passes'] ?? 1),
                    'min_severity' => (string) ($payload['qa_loop_min_severity'] ?? 'medium'),
                ],
            );
            $this->scheduleSimpleMemoryRefresh($payload, $result, $logger);

            return response()->json(array_merge($result, [
                'sceneCreated' => $sceneCreated,
                'scene' => [
                    'id' => $sceneRecord->id,
                    'title' => $sceneRecord->title ?: $sceneRecord->id,
                    'vault_id' => (string) $sceneRecord->vault_id,
                    'status' => (string) $sceneRecord->status,
                ],
            ]));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($scopedLogger, $exception, 422, 'api.v2.scene.create');
        } catch (Throwable $exception) {
            return $this->errorResponse($scopedLogger, $exception, 500, 'api.v2.scene.create');
        }
    }

    public function bootstrap(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'scene_id' => ['required', 'string'],
            'vault_id' => ['required', 'string'],
            'title' => ['nullable', 'string'],
            'location_id' => ['required', 'string'],
            'quest_id' => ['nullable', 'string'],
            'quest_prompt' => ['nullable', 'string'],
            'continuity_id' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $sceneId = trim((string) $payload['scene_id']);
        $scopedLogger = $logger->withContext([
            'layer' => 'http',
            'endpoint' => 'api.v2.scene.bootstrap',
            'sceneId' => $sceneId,
            'vaultId' => trim((string) ($payload['vault_id'] ?? '')),
        ]);

        try {
            /** @var CreateSceneBootstrapUseCase $useCase */
            $useCase = app(CreateSceneBootstrapUseCase::class);

            return response()->json($useCase->execute($payload));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($scopedLogger, $exception, 422, 'api.v2.scene.bootstrap');
        } catch (Throwable $exception) {
            return $this->errorResponse($scopedLogger, $exception, 500, 'api.v2.scene.bootstrap');
        }
    }

    public function timeline(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scene_id' => ['required', 'string'],
            'continuity_id' => ['nullable', 'string'],
        ]);

        $sceneId = $payload['scene_id'];
        $continuityId = $payload['continuity_id'] ?? null;

        $turnsQuery = ContinuityTurn::query()
            ->where('activity_id', $sceneId)
            ->orderBy('turn_index');
        $commitsQuery = ContinuityCommit::query()
            ->where('activity_id', $sceneId)
            ->orderBy('turn_index')
            ->orderBy('id');
        $stateChangesQuery = ContinuityStateChange::query()
            ->where('activity_id', $sceneId)
            ->orderBy('id');

        if (is_string($continuityId) && trim($continuityId) !== '') {
            $turnsQuery->where('continuity_id', $continuityId);
            $commitsQuery->where('continuity_id', $continuityId);
            $stateChangesQuery->where('continuity_id', $continuityId);
        }

        $scene = SceneRecord::query()->find($sceneId);
        $vaultId = $scene ? (string) $scene->vault_id : null;
        $quests = [];
        if ($vaultId && is_string($continuityId) && trim($continuityId) !== '') {
            /** @var \App\Application\Contracts\ContinuityQuestStatusRepository $questRepo */
            $questRepo = app(\App\Application\Contracts\ContinuityQuestStatusRepository::class);
            $quests = $questRepo->listForSceneContext($continuityId, $vaultId);
        }

        return response()->json([
            'sceneId' => $sceneId,
            'continuityId' => $continuityId,
            'quests' => $quests,
            'turns' => $turnsQuery->get()->map(static function (ContinuityTurn $turn): array {
                return [
                    'id' => $turn->id,
                    'continuityId' => $turn->continuity_id,
                    'sceneId' => $turn->activity_id,
                    'turnIndex' => $turn->turn_index,
                    'mode' => $turn->mode,
                    'userMessage' => $turn->user_message,
                    'outputMd' => $turn->output_md,
                ];
            })->all(),
            'commits' => $commitsQuery->get()->map(static function (ContinuityCommit $commit): array {
                return [
                    'id' => $commit->id,
                    'continuityId' => $commit->continuity_id,
                    'sceneId' => $commit->activity_id,
                    'parentCommitId' => $commit->parent_commit_id,
                    'turnIndex' => $commit->turn_index,
                    'mode' => $commit->mode,
                    'message' => $commit->message,
                ];
            })->all(),
            'stateChanges' => $stateChangesQuery->get()->map(static function (ContinuityStateChange $change): array {
                return [
                    'id' => $change->id,
                    'continuityId' => $change->continuity_id,
                    'sceneId' => $change->activity_id,
                    'kind' => $change->kind,
                    'scopeType' => $change->scope_type,
                    'scopeId' => $change->scope_id,
                    'change' => $change->change,
                    'severity' => $change->severity,
                ];
            })->all(),
        ]);
    }

    public function context(
        Request $request,
        \App\Application\Contracts\SceneContextBuilder $sceneContextBuilder,
        StructuredLogger $logger
    ): JsonResponse {
        $payload = $request->validate([
            'scene_id' => ['required', 'string'],
            'continuity_id' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $sceneId = $payload['scene_id'];
        $continuityId = $payload['continuity_id'] ?? null;
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : auth()->id();

        try {
            $context = $sceneContextBuilder->build($sceneId, $continuityId, $userId);

            return response()->json([
                'sceneId' => $sceneId,
                'continuityId' => $continuityId,
                'context' => $context,
            ]);
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500, 'api.v2.scene.context');
        }
    }

    public function fork(Request $request, string $id, StructuredLogger $logger): JsonResponse
    {
        $payload = $request->validate([
            'new_scene_id' => ['required', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:255'],
            'commit_id' => ['nullable', 'integer', 'min:1'],
            'new_continuity_id' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
            'reassign_controls' => ['nullable', 'array'],
            'reassign_controls.*' => ['nullable', 'integer'],
        ]);

        // El usuario que realiza el fork debe indicarse explícitamente en el payload.
        // Si no se envía user_id, el fork se crea sin asignación de admin.
        $payload['forking_user_id'] = isset($payload['user_id']) && (int) $payload['user_id'] > 0
            ? (int) $payload['user_id']
            : null;

        $scopedLogger = $logger->withContext([
            'layer' => 'http',
            'endpoint' => 'api.v2.scene.fork',
            'sourceSceneId' => $id,
        ]);

        try {
            /** @var TimelineForkingService $service */
            $service = app(TimelineForkingService::class);
            $result = $service->fork($id, $payload);

            return response()->json($result, 201);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($scopedLogger, $exception, 422, 'api.v2.scene.fork');
        } catch (Throwable $exception) {
            return $this->errorResponse($scopedLogger, $exception, 500, 'api.v2.scene.fork');
        }
    }

    public function sceneState(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scene_id' => ['required', 'string'],
        ]);

        $sceneId = trim((string) $payload['scene_id']);
        $scene = SceneRecord::query()->find($sceneId);

        if (! $scene instanceof SceneRecord) {
            return response()->json(['error' => 'Escena no encontrada.'], 404);
        }

        $characters = \DB::table('activity_members')
            ->join('avatars', 'activity_members.avatar_id', '=', 'avatars.id')
            ->where('activity_members.activity_id', $sceneId)
            ->select([
                'avatars.id',
                'avatars.name',
                'activity_members.scene_role',
                'activity_members.controlled_by_player_id',
                'activity_members.initiative_score',
                'activity_members.has_acted_this_round',
            ])
            ->orderBy('activity_members.initiative_score', 'desc')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'scene_role' => $row->scene_role ?? 'npc',
                'controlled_by_player_id' => $row->controlled_by_player_id,
                'initiative_score' => (int) $row->initiative_score,
                'has_acted_this_round' => (bool) $row->has_acted_this_round,
            ])
            ->all();

        return response()->json([
            'sceneId' => $sceneId,
            'status' => (string) $scene->status,
            'currentTurnCharacterId' => $scene->current_turn_character_id,
            'roundNumber' => (int) ($scene->round_number ?? 1),
            'characters' => $characters,
        ]);
    }

    public function attachCharacter(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'scene_id' => ['required', 'string'],
            'character_id' => ['required', 'string'],
            'role' => ['nullable', 'string'],
        ]);

        $sceneId = trim((string) $payload['scene_id']);
        $characterId = trim((string) $payload['character_id']);
        $role = trim((string) ($payload['role'] ?? 'supporting'));
        $role = $role !== '' ? $role : 'supporting';

        $scopedLogger = $logger->withContext([
            'layer' => 'http',
            'endpoint' => 'api.v2.scene.characters.attach',
            'sceneId' => $sceneId,
            'characterId' => $characterId,
        ]);

        try {
            $scene = SceneRecord::query()->with('characters:id,name')->findOrFail($sceneId);
            $character = CharacterRecord::query()->findOrFail($characterId);

            if ((string) $scene->vault_id !== (string) $character->vault_id) {
                throw new RuntimeException('El personaje pertenece a otro vault y no se puede importar a esta escena.');
            }

            $scene->characters()->syncWithoutDetaching([
                $character->id => ['role' => $role],
            ]);
            $scene->load('characters:id,name');

            $characters = $scene->characters
                ->map(static fn ($item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'role' => (string) ($item->pivot->role ?? ''),
                ])
                ->values()
                ->all();

            // Crear instantánea del personaje al entrar a la escena
            /** @var CharacterSnapshotService $snapshotService */
            $snapshotService = app(CharacterSnapshotService::class);
            $snapshotResult = $snapshotService->snapshot($sceneId, $characterId);

            $scopedLogger->info('Personaje importado a escena', [
                'role' => $role,
                'sceneCharacterCount' => count($characters),
                'snapshotCreated' => $snapshotResult['created'],
                'snapshotVersion' => $snapshotResult['instance']->version,
            ]);

            return response()->json([
                'activityId' => $sceneId,
                'avatarId' => $characterId,
                'role' => $role,
                'characters' => $characters,
                'snapshot' => [
                    'version' => $snapshotResult['instance']->version,
                    'created' => $snapshotResult['created'],
                    'snapshotted_at' => $snapshotResult['instance']->snapshotted_at,
                ],
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($scopedLogger, $exception, 422, 'api.v2.scene.characters.attach');
        } catch (Throwable $exception) {
            return $this->errorResponse($scopedLogger, $exception, 500, 'api.v2.scene.characters.attach');
        }
    }

    private function errorResponse(
        StructuredLogger $logger,
        Throwable $exception,
        int $status,
        string $endpoint,
    ): JsonResponse {
        $logger
            ->withContext([
                'layer' => 'http',
                'endpoint' => $endpoint,
                'status' => $status,
            ])
            ->error('Solicitud API v2 fallo', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

        return response()->json([
            'error' => $exception->getMessage(),
        ], $status);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{bool, SceneRecord}
     */
    private function ensureSceneExists(array $payload, StructuredLogger $logger): array
    {
        $sceneId = trim((string) ($payload['scene_id'] ?? ''));
        $scene = SceneRecord::query()->find($sceneId);

        if ($scene instanceof SceneRecord) {
            $logger->info('Escena reutilizada para request de chat', [
                'sceneCreated' => false,
                'vaultId' => (string) $scene->vault_id,
            ]);

            return [false, $scene];
        }

        $vaultId = trim((string) ($payload['vault_id'] ?? ''));
        if ($vaultId === '') {
            throw new RuntimeException('Selecciona un vault antes de crear una escena nueva.');
        }

        $vault = \App\Infrastructure\Persistence\Eloquent\Models\VaultRecord::query()->find($vaultId);
        if (! $vault) {
            throw new RuntimeException('El vault seleccionado no existe.');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $title = str_replace(['_', '-'], ' ', $sceneId);
        }
        if ($title === '') {
            $title = $sceneId;
        }

        $sceneCount = SceneRecord::query()->where('vault_id', $vaultId)->count();

        $scene = SceneRecord::query()->create([
            'id' => $sceneId,
            'vault_id' => $vaultId,
            'title' => $title,
            'chapter' => 1,
            'scene_number' => $sceneCount + 1,
            'status' => 'draft',
            'location_id' => null,
            'objective' => null,
            'constraints' => null,
            'draft' => '',
        ]);

        $logger->info('Escena creada automaticamente para request de chat', [
            'sceneCreated' => true,
            'title' => $title,
            'vaultId' => $vaultId,
            'sceneNumber' => $sceneCount + 1,
        ]);

        return [true, $scene];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     */
    private function scheduleSimpleMemoryRefresh(array $payload, array $result, StructuredLogger $logger): void
    {
        if (($result['sceneType'] ?? null) !== 'simple') {
            return;
        }

        if (($result['applied'] ?? true) !== true) {
            return;
        }

        $sceneId = (string) ($result['sceneId'] ?? $payload['scene_id'] ?? '');
        $userMessage = trim((string) ($payload['user_message'] ?? ''));
        $outputMd = trim((string) ($result['outputMd'] ?? ''));
        $userId = isset($payload['user_id']) && is_numeric($payload['user_id'])
            ? (int) $payload['user_id']
            : auth()->id();

        if ($sceneId === '' || $userMessage === '' || $outputMd === '') {
            return;
        }

        app()->terminating(function () use ($sceneId, $userMessage, $outputMd, $userId, $logger): void {
            try {
                app(RefreshSimpleChatMemoryUseCase::class)->execute(
                    sceneId: $sceneId,
                    userMessage: $userMessage,
                    outputMd: $outputMd,
                    userId: $userId,
                );
            } catch (Throwable $exception) {
                $logger->withContext([
                    'layer' => 'http',
                    'sceneId' => $sceneId,
                    'post_response' => 'simple_memory_refresh',
                ])->error('Refresh de memoria simple post-respuesta fallo', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }
}
