<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Contracts\AgentGateway;
use App\Application\Contracts\SceneContextBuilder;
use App\Application\Contracts\SceneRepository;
use App\Application\Contracts\StructuredLogger;
use App\Models\CharacterInstance;
use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Motor de Procesamiento de Turnos VTT.
 *
 * Coordina la generación de un turno narrativo en modo VTT:
 *  1. Valida que la escena esté activa y sea el turno del personaje.
 *  2. Verifica que el usuario controla el personaje en la escena.
 *  3. Carga el snapshot del personaje (CharacterInstance) como Memoria de Escena.
 *  4. Construye el contexto priorizando datos del snapshot sobre el Baúl.
 *  5. Invoca al AgentGateway para generar la narrativa.
 *  6. Persiste el draft actualizado si apply=true.
 */
final class TurnProcessorService
{
    public function __construct(
        private readonly SceneRepository $sceneRepository,
        private readonly SceneContextBuilder $sceneContextBuilder,
        private readonly AgentGateway $agentGateway,
        private readonly CharacterSnapshotService $snapshotService,
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Procesa un turno del jugador en modo VTT.
     *
     * @param array{
     *   scene_id: string,
     *   character_id: string,
     *   user_id: string,
     *   user_message: string,
     *   mode?: string,
     *   apply?: bool,
     *   continuity_id?: string|null,
     * } $input
     *
     * @return array{
     *   sceneId: string,
     *   characterId: string,
     *   outputMd: string,
     *   applied: bool,
     *   snapshotVersion: int|null,
     *   snapshotUsed: bool,
     * }
     *
     * @throws InvalidArgumentException si la validación de turno falla
     * @throws RuntimeException si la escena no existe
     */
    public function process(array $input): array
    {
        $sceneId = trim((string) ($input['scene_id'] ?? ''));
        $characterId = trim((string) ($input['character_id'] ?? ''));
        $userId = trim((string) ($input['user_id'] ?? ''));
        $userMessage = trim((string) ($input['user_message'] ?? ''));
        $mode = (string) ($input['mode'] ?? 'write_scene');
        $apply = (bool) ($input['apply'] ?? true);
        $continuityId = isset($input['continuity_id']) ? trim((string) $input['continuity_id']) : null;
        if ($continuityId === '') {
            $continuityId = null;
        }

        if ($sceneId === '') {
            throw new InvalidArgumentException('scene_id es requerido.');
        }
        if ($characterId === '') {
            throw new InvalidArgumentException('character_id es requerido.');
        }
        if ($userId === '') {
            throw new InvalidArgumentException('user_id es requerido.');
        }
        if ($userMessage === '') {
            throw new InvalidArgumentException('user_message es requerido.');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'service' => 'turn_processor',
            'sceneId' => $sceneId,
            'characterId' => $characterId,
            'userId' => $userId,
        ]);

        $logger->info('Inicio de procesamiento de turno VTT');

        // 1. Validar escena activa
        $sceneModel = Activity::query()->find($sceneId);
        if (! $sceneModel instanceof Activity) {
            throw new RuntimeException("Escena no encontrada: {$sceneId}");
        }

        $this->validateSceneActive($sceneModel, $sceneId);
        $this->validateTurnOwnership($sceneId, $characterId, $userId);

        $logger->info('Validaciones de turno superadas');

        // 2. Cargar snapshot del personaje (Memoria de Escena)
        $snapshot = $this->snapshotService->getSnapshot($sceneId, $characterId);
        $snapshotUsed = $snapshot instanceof CharacterInstance;
        $snapshotVersion = $snapshotUsed ? $snapshot->version : null;

        if ($snapshotUsed) {
            $logger->info('Snapshot encontrado para personaje', [
                'version' => $snapshotVersion,
                'inventoryItems' => count($snapshot->inventory()),
            ]);
        } else {
            $logger->info('Sin snapshot: usando datos base del Baúl', [
                'characterId' => $characterId,
            ]);
        }

        // 3. Construir contexto — el SceneContextBuilder ya prioriza snapshots cuando están disponibles
        $context = $this->sceneContextBuilder->build($sceneId, $continuityId, $userId);

        // 4. Inyectar metadatos de snapshot en el contexto para trazabilidad del pipeline
        $context['vtt'] = [
            'characterId' => $characterId,
            'userId' => $userId,
            'snapshotUsed' => $snapshotUsed,
            'snapshotVersion' => $snapshotVersion,
        ];

        // 5. Recuperar la escena de dominio para el AgentGateway
        $domainScene = $this->sceneRepository->findById($sceneId);
        if (! $domainScene) {
            throw new RuntimeException("Escena de dominio no encontrada: {$sceneId}");
        }

        // 6. Generar narrativa
        $generated = $this->agentGateway->generateSceneTurn(
            scene: $domainScene,
            context: $context,
            userMessage: $userMessage,
            mode: $mode,
            sceneType: 'simple',
            onChunk: null,
            userId: $userId,
        );

        $outputMd = trim((string) ($generated['outputMd'] ?? ''));

        // 7. Persistir draft si apply=true
        if ($apply && $outputMd !== '') {
            $updated = new \App\Domain\Scene\Scene(
                id: $domainScene->id,
                vaultId: $domainScene->vaultId,
                title: $domainScene->title,
                chapter: $domainScene->chapter,
                sceneNumber: $domainScene->sceneNumber,
                status: $domainScene->status,
                locationId: $domainScene->locationId,
                objective: $domainScene->objective,
                constraints: $domainScene->constraints,
                draft: trim((string) $domainScene->draft) . "\n\n" . $outputMd,
                characters: $domainScene->characters,
            );
            $this->sceneRepository->save($updated);
        }

        $logger->info('Turno VTT procesado', [
            'applied' => $apply,
            'snapshotUsed' => $snapshotUsed,
            'outputChars' => mb_strlen($outputMd),
        ]);

        return [
            'sceneId' => $sceneId,
            'characterId' => $characterId,
            'outputMd' => $outputMd,
            'applied' => $apply,
            'snapshotVersion' => $snapshotVersion,
            'snapshotUsed' => $snapshotUsed,
        ];
    }

    /**
     * Valida que la escena esté en estado activo para recibir turnos.
     */
    private function validateSceneActive(Activity $scene, string $sceneId): void
    {
        $status = $scene->status instanceof \App\Domains\Matchmaking\Enums\ActivityStatus
            ? $scene->status->value
            : $scene->status;

        $activeStatuses = ['ready', 'in_progress'];
        if (! in_array($status, $activeStatuses, true)) {
            throw new InvalidArgumentException(
                "La escena '{$sceneId}' no está activa (estado: {$status}). Solo se aceptan turnos en escenas 'ready' o 'in_progress'."
            );
        }
    }

    /**
     * Valida que:
     *  a) El usuario controla el personaje en la escena.
     *  b) Es el turno de ese personaje (si current_turn_character_id está definido).
     */
    private function validateTurnOwnership(string $sceneId, string $characterId, string $userId): void
    {
        $pivot = DB::table('activity_members')
            ->where('activity_id', $sceneId)
            ->where('avatar_id', $characterId)
            ->first();

        if (! $pivot) {
            throw new InvalidArgumentException(
                "El personaje '{$characterId}' no pertenece a la escena '{$sceneId}'."
            );
        }

        if ($pivot->controlled_by_player_id === null || (string) $pivot->controlled_by_player_id !== $userId) {
            throw new InvalidArgumentException(
                "El usuario {$userId} no controla al personaje '{$characterId}' en esta escena."
            );
        }

        // Si la escena tiene turno activo, validar que sea el de este personaje
        $currentTurn = DB::table('activities')
            ->where('id', $sceneId)
            ->value('current_turn_character_id');

        if ($currentTurn !== null && $currentTurn !== $characterId) {
            throw new InvalidArgumentException(
                "No es el turno de '{$characterId}'. Turno actual: '{$currentTurn}'."
            );
        }
    }
}
