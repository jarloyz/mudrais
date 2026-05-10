<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Contracts\StructuredLogger;
use App\Models\Continuity;
use App\Models\ContinuityCommit;
use App\Models\ContinuityCommitSceneState;
use App\Models\ContinuitySceneState;
use App\Models\Activity;
use App\Models\SceneActiveContinuity;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;
use InvalidArgumentException;

/**
 * Motor de Bifurcación (Timeline Forking).
 *
 * Clona una escena con su historial de continuidad en un nuevo nodo independiente,
 * preservando o reasignando los controles de personajes. No modifica la escena fuente.
 */
final class TimelineForkingService
{
    public function __construct(
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Crea un fork de la escena fuente.
     *
     * @param array{
     *   new_scene_id: string,
     *   title?: string,
     *   commit_id?: int|null,
     *   new_continuity_id?: string,
     *   forking_player_id?: int|null,
     *   reassign_controls?: array<string, int|null>,
     * } $input
     *
     * @return array{
     *   sourceSceneId: string,
     *   forkedSceneId: string,
     *   continuityId: string,
     *   parentContinuityId: string|null,
     *   sourceCommitId: int|null,
     *   headCommitId: int|null,
     *   status: string,
     *   forkingPlayerId: int|null,
     *   adminAssigned: bool,
     * }
     */
    public function fork(string $sourceSceneId, array $input): array
    {
        $sourceSceneId = trim($sourceSceneId);
        $newSceneId = trim((string) ($input['new_scene_id'] ?? ''));

        if ($sourceSceneId === '') {
            throw new InvalidArgumentException('sourceSceneId es requerido.');
        }
        if ($newSceneId === '') {
            throw new InvalidArgumentException('new_scene_id es requerido.');
        }
        if ($newSceneId === $sourceSceneId) {
            throw new InvalidArgumentException('new_scene_id debe ser distinto al de la escena fuente.');
        }

        $sourceScene = Activity::query()->find($sourceSceneId);
        if (! $sourceScene instanceof Activity) {
            throw new InvalidArgumentException("Escena fuente no encontrada: {$sourceSceneId}");
        }

        if (Activity::query()->find($newSceneId) !== null) {
            throw new InvalidArgumentException("Ya existe una escena con id: {$newSceneId}");
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'service' => 'timeline_forking',
            'sourceSceneId' => $sourceSceneId,
            'newSceneId' => $newSceneId,
        ]);

        $logger->info('Inicio de fork de escena');

        // Resolver continuidad activa del source
        $sourceActiveCont = SceneActiveContinuity::query()->where('activity_id', $sourceSceneId)->first();
        $parentContinuityId = $sourceActiveCont?->continuity_id;

        // Resolver commit a bifurcar (el especificado o el HEAD)
        $commitId = isset($input['commit_id']) && (int) $input['commit_id'] > 0
            ? (int) $input['commit_id']
            : null;

        $sourceCommit = null;
        if ($commitId === null && $parentContinuityId !== null) {
            $sourceCommit = ContinuityCommit::query()
                ->where('continuity_id', $parentContinuityId)
                ->where('activity_id', $sourceSceneId)
                ->orderByDesc('id')
                ->first();
            $commitId = $sourceCommit ? (int) $sourceCommit->id : null;
        } elseif ($commitId !== null) {
            $sourceCommit = ContinuityCommit::query()->find($commitId);
            if (! $sourceCommit) {
                throw new InvalidArgumentException("Commit no encontrado: {$commitId}");
            }
        }

        $newContinuityId = trim((string) ($input['new_continuity_id'] ?? ''));
        if ($newContinuityId === '') {
            $newContinuityId = $newSceneId . '_cont';
        }

        if (Continuity::query()->find($newContinuityId) !== null) {
            throw new InvalidArgumentException("Ya existe una continuidad con id: {$newContinuityId}");
        }

        $reassignControls = is_array($input['reassign_controls'] ?? null)
            ? $input['reassign_controls']
            : [];

        $forkingPlayerId = isset($input['user_id']) && (int) $input['user_id'] > 0
            ? (int) $input['user_id']
            : (isset($input['forking_player_id']) && (int) $input['forking_player_id'] > 0
                ? (int) $input['forking_player_id']
                : null);

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = ($sourceScene->title ? $sourceScene->title . ' [fork]' : $newSceneId);
        }

        $headCommitId = null;
        $adminAssigned = false;

        DB::transaction(function () use (
            $sourceScene,
            $sourceSceneId,
            $newSceneId,
            $newContinuityId,
            $parentContinuityId,
            $commitId,
            $sourceCommit,
            $title,
            $reassignControls,
            $forkingPlayerId,
            &$headCommitId,
            &$adminAssigned,
            $logger,
        ): void {
            // 1. Clonar la escena — estado 'draft' para el fork, turno reseteado
            Activity::query()->create([
                'id' => $newSceneId,
                'vault_id' => $sourceScene->vault_id,
                'title' => $title,
                'chapter' => $sourceScene->chapter,
                'scene_number' => $sourceScene->scene_number,
                'status' => 'draft',
                'location_id' => $sourceScene->location_id,
                'objective' => $sourceScene->objective,
                'constraints' => $sourceScene->constraints,
                'draft' => $sourceScene->draft,
                'current_turn_character_id' => null,
                'round_number' => 1,
            ]);

            $logger->info('Escena forkeada creada');

            // 2. Asignar rol 'admin' al usuario que realiza el fork
            if ($forkingPlayerId !== null) {
                DB::table('activity_members')->insert([
                    'id' => (string) Uuid::v7(),
                    'activity_id' => $newSceneId,
                    'controlled_by_player_id' => $forkingPlayerId,
                    'role' => 'admin',
                ]);
                $adminAssigned = true;
                $logger->info('Rol admin asignado al jugador que realiza el fork', [
                    'playerId' => $forkingPlayerId,
                ]);
            }

            // 3. Clonar scene_characters aplicando la política de ex-compañeros:
            //    - Personajes del forking_player_id → conservan su control
            //    - Personajes de OTROS usuarios → se convierten en NPC (null)
            //    - Reasignaciones explícitas (reassign_controls) tienen prioridad
            $characters = DB::table('activity_members')
                ->where('activity_id', $sourceSceneId)
                ->get();

            foreach ($characters as $row) {
                $charId = (string) $row->avatar_id;
                $originalPlayerId = $row->controlled_by_player_id !== null
                    ? (int) $row->controlled_by_player_id
                    : null;

                if (array_key_exists($charId, $reassignControls)) {
                    // Reasignación explícita tiene prioridad
                    $newPlayerId = $reassignControls[$charId] !== null
                        ? (int) $reassignControls[$charId]
                        : null;
                } elseif ($forkingPlayerId !== null && $originalPlayerId !== null && $originalPlayerId !== $forkingPlayerId) {
                    // Ex-compañero: convertir en NPC
                    $newPlayerId = null;
                } else {
                    $newPlayerId = $originalPlayerId;
                }

                DB::table('activity_members')->insert([
                    'id' => (string) Uuid::v7(),
                    'activity_id' => $newSceneId,
                    'avatar_id' => $charId,
                    'scene_role' => $row->scene_role ?? 'npc',
                    'controlled_by_player_id' => $newPlayerId,
                    'initiative_score' => (int) ($row->initiative_score ?? 0),
                    'has_acted_this_round' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $logger->info('Personajes clonados', ['count' => $characters->count()]);

            // 3. Crear la continuidad nueva (branch del parent sin modificar el source)
            $rootId = $parentContinuityId
                ? (Continuity::query()->find($parentContinuityId)?->root_id ?? $parentContinuityId)
                : $newContinuityId;

            Continuity::query()->create([
                'id' => $newContinuityId,
                'parent_id' => $parentContinuityId,
                'root_id' => $rootId,
                'label' => "Fork de {$sourceSceneId}" . ($commitId ? " @commit{$commitId}" : ''),
                'status' => 'active',
            ]);

            // 4. Copiar snapshot del commit fuente (si existe) al nuevo scene_id
            if ($sourceCommit !== null) {
                $snapshot = ContinuityCommitSceneState::query()
                    ->where('commit_id', $sourceCommit->id)
                    ->where('activity_id', $sourceSceneId)
                    ->first();

                $draftFromSnapshot = $snapshot?->draft ?? $sourceScene->draft;
                $objectiveFromSnapshot = $snapshot?->objective ?? $sourceScene->objective;
                $constraintsFromSnapshot = $snapshot?->constraints ?? $sourceScene->constraints;

                // Estado de escena en la nueva continuidad
                ContinuitySceneState::query()->create([
                    'continuity_id' => $newContinuityId,
                    'activity_id' => $newSceneId,
                    'objective' => $objectiveFromSnapshot,
                    'constraints' => $constraintsFromSnapshot,
                    'draft' => $draftFromSnapshot,
                ]);

                // Commit inicial del fork con referencia al commit fuente
                $newCommit = ContinuityCommit::query()->create([
                    'continuity_id' => $newContinuityId,
                    'activity_id' => $newSceneId,
                    'parent_commit_id' => null,
                    'source_parent_commit_id' => $sourceCommit->id,
                    'turn_index' => $sourceCommit->turn_index,
                    'mode' => $sourceCommit->mode,
                    'message' => "Fork de {$sourceSceneId}@commit{$sourceCommit->id}",
                ]);

                ContinuityCommitSceneState::query()->create([
                    'commit_id' => $newCommit->id,
                    'continuity_id' => $newContinuityId,
                    'activity_id' => $newSceneId,
                    'objective' => $objectiveFromSnapshot,
                    'constraints' => $constraintsFromSnapshot,
                    'draft' => $draftFromSnapshot,
                ]);

                $headCommitId = (int) $newCommit->id;

                $logger->info('Continuidad forkeada con historial', [
                    'parentContinuityId' => $parentContinuityId,
                    'sourceCommitId' => $sourceCommit->id,
                    'headCommitId' => $headCommitId,
                ]);
            } else {
                // Sin historial: estado inicial desde la escena fuente
                ContinuitySceneState::query()->create([
                    'continuity_id' => $newContinuityId,
                    'activity_id' => $newSceneId,
                    'objective' => $sourceScene->objective,
                    'constraints' => $sourceScene->constraints,
                    'draft' => $sourceScene->draft,
                ]);

                $logger->info('Continuidad creada sin historial previo');
            }

            // 5. Vincular la escena forkeada a la nueva continuidad
            //    La escena source NO se toca — mantiene su propia continuidad activa
            SceneActiveContinuity::query()->create([
                'activity_id' => $newSceneId,
                'continuity_id' => $newContinuityId,
                'continuity_commit_id' => $headCommitId,
            ]);

            $logger->info('SceneActiveContinuity vinculada al fork', [
                'continuityId' => $newContinuityId,
                'headCommitId' => $headCommitId,
            ]);
        });

        $logger->info('Fork de escena completado exitosamente');

        return [
            'sourceSceneId' => $sourceSceneId,
            'forkedSceneId' => $newSceneId,
            'continuityId' => $newContinuityId,
            'parentContinuityId' => $parentContinuityId,
            'sourceCommitId' => $commitId,
            'headCommitId' => $headCommitId,
            'status' => 'draft',
            'forkingPlayerId' => $forkingPlayerId,
            'adminAssigned' => $adminAssigned,
        ];
    }
}
