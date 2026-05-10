<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\ContinuityRepository;
use App\Models\Continuity;
use App\Models\ContinuityCommit;
use App\Models\ContinuityCommitSceneState;
use App\Models\ContinuitySceneState;
use App\Models\ContinuityStateChange;
use App\Models\ContinuityTurn;
use App\Domain\Scene\Activity;
use App\Models\SceneActiveContinuity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EloquentContinuityRepository implements ContinuityRepository
{
    public function requireById(string $continuityId): array
    {
        $record = Continuity::query()->find($continuityId);

        if (! $record) {
            throw new RuntimeException("continuidad no encontrada: {$continuityId}");
        }

        return $this->mapContinuity($record);
    }

    public function createBranch(array $input): array
    {
        $newContinuityId = trim((string) ($input['newContinuityId'] ?? ''));
        $parentContinuityId = trim((string) ($input['parentContinuityId'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));

        if ($newContinuityId === '') {
            throw new RuntimeException('newContinuityId es requerido');
        }
        if ($parentContinuityId === '') {
            throw new RuntimeException('parentContinuityId es requerido');
        }
        if ($newContinuityId === $parentContinuityId) {
            throw new RuntimeException('newContinuityId debe ser distinto a parentContinuityId');
        }

        $parent = Continuity::query()->find($parentContinuityId);
        if (! $parent) {
            throw new RuntimeException("continuidad no encontrada: {$parentContinuityId}");
        }

        DB::transaction(function () use ($newContinuityId, $parent, $label): void {
            Continuity::query()->create([
                'id' => $newContinuityId,
                'parent_id' => $parent->id,
                'root_id' => $parent->root_id ?: $parent->id,
                'label' => $label !== '' ? $label : "{$newContinuityId} (branch)",
                'status' => 'active',
            ]);
        });

        return $this->requireById($newContinuityId);
    }

    public function ensureSceneStateFromBase(array $input): void
    {
        /** @var Activity|null $scene */
        $scene = $input['scene'] ?? null;
        $continuityId = trim((string) ($input['continuityId'] ?? ''));

        if (! $scene || $continuityId === '') {
            throw new RuntimeException('ensureSceneStateFromBase requiere continuityId y scene');
        }

        ContinuitySceneState::query()->firstOrCreate(
            [
                'continuity_id' => $continuityId,
                'activity_id' => $scene->id,
            ],
            [
                'objective' => $scene->objective,
                'constraints' => $scene->constraints,
                'draft' => $scene->draft,
            ]
        );
    }

    public function requireSceneState(array $input): array
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));

        $record = ContinuitySceneState::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->first();

        if (! $record) {
            throw new RuntimeException("scene_state no encontrado para continuidad={$continuityId} escena={$sceneId}");
        }

        return [
            'continuity_id' => $record->continuity_id,
            'activity_id' => $record->activity_id,
            'objective' => $record->objective,
            'constraints' => $record->constraints,
            'draft' => $record->draft,
        ];
    }

    public function appendSceneDraft(array $input): void
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $additionMd = trim((string) ($input['additionMd'] ?? ''));

        $record = ContinuitySceneState::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->firstOrFail();

        $record->update([
            'draft' => trim((string) $record->draft) === ''
                ? $additionMd
                : trim((string) $record->draft)."\n\n".$additionMd,
        ]);
    }

    public function replaceSceneDraft(array $input): void
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $draftMd = trim((string) ($input['draftMd'] ?? ''));

        ContinuitySceneState::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->firstOrFail()
            ->update(['draft' => $draftMd]);
    }

    public function nextTurnIndex(array $input): int
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));

        return (int) ContinuityTurn::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->max('turn_index') + 1;
    }

    public function appendTurn(array $input): array
    {
        $record = ContinuityTurn::query()->create([
            'continuity_id' => trim((string) ($input['continuityId'] ?? '')),
            'activity_id' => trim((string) ($input['sceneId'] ?? '')),
            'turn_index' => (int) ($input['turnIndex'] ?? 0),
            'mode' => trim((string) ($input['mode'] ?? 'write_scene')) === 'rewrite_scene' ? 'rewrite_scene' : 'write_scene',
            'user_message' => trim((string) ($input['userMessage'] ?? '')),
            'output_md' => trim((string) ($input['outputMd'] ?? '')),
            'notes_json' => ($input['notes'] ?? null) ? json_encode($input['notes'], JSON_UNESCAPED_UNICODE) : null,
        ]);

        return ['turnIndex' => $record->turn_index];
    }

    public function appendStateChanges(array $input): int
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];
        $count = 0;

        foreach ($changes as $change) {
            $raw = trim((string) ($change['change'] ?? ''));
            if ($raw === '') {
                continue;
            }

            ContinuityStateChange::query()->create([
                'continuity_id' => $continuityId,
                'activity_id' => $sceneId,
                'kind' => trim((string) ($change['kind'] ?? 'state')) ?: 'state',
                'scope_type' => trim((string) ($change['scope_type'] ?? 'scene')) ?: 'scene',
                'scope_id' => ($change['scope_id'] ?? null) ? trim((string) $change['scope_id']) : null,
                'change' => $raw,
                'severity' => max(1, min(5, (int) ($change['severity'] ?? 1))),
            ]);
            $count++;
        }

        return $count;
    }

    public function getHeadCommit(array $input): ?array
    {
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $continuityId = trim((string) ($input['continuityId'] ?? ''));

        $active = SceneActiveContinuity::query()->find($sceneId);
        if ($active && $active->continuity_id === $continuityId && $active->continuity_commit_id) {
            return $this->requireCommitById((string) $active->continuity_commit_id);
        }

        $record = ContinuityCommit::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->orderByDesc('created_at')
            ->first();

        return $record ? $this->mapCommit($record) : null;
    }

    public function requireCommitById(string $commitId): array
    {
        $record = ContinuityCommit::query()->find($commitId);

        if (! $record) {
            throw new RuntimeException("commit no encontrado: {$commitId}");
        }

        return $this->mapCommit($record);
    }

    public function requireCommitByTurn(array $input): array
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $turnIndex = (int) ($input['turnIndex'] ?? 0);

        $record = ContinuityCommit::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->where('turn_index', $turnIndex)
            ->orderByDesc('id')
            ->first();

        if (! $record) {
            throw new RuntimeException("commit no encontrado para turn {$turnIndex} en {$continuityId}/{$sceneId}");
        }

        return $this->mapCommit($record);
    }

    public function checkoutCommit(array $input): array
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $commitId = trim((string) ($input['commitId'] ?? ''));

        if ($continuityId === '' || $sceneId === '' || $commitId === '') {
            throw new RuntimeException('checkoutCommit requiere continuityId, sceneId y commitId validos');
        }

        DB::transaction(function () use ($sceneId, $continuityId, $commitId): void {
            $snapshot = ContinuityCommitSceneState::query()
                ->where('commit_id', $commitId)
                ->where('activity_id', $sceneId)
                ->first();

            if (! $snapshot) {
                throw new RuntimeException("snapshot de escena no encontrado para commit {$commitId}");
            }

            ContinuitySceneState::query()->updateOrCreate(
                [
                    'continuity_id' => $continuityId,
                    'activity_id' => $sceneId,
                ],
                [
                    'objective' => $snapshot->objective,
                    'constraints' => $snapshot->constraints,
                    'draft' => $snapshot->draft,
                ]
            );

            SceneActiveContinuity::query()->updateOrCreate(
                ['activity_id' => $sceneId],
                [
                    'continuity_id' => $continuityId,
                    'continuity_commit_id' => $commitId,
                ]
            );
        });

        $active = $this->getActiveSceneContinuity($sceneId)
            ?? throw new RuntimeException("no se pudo activar continuidad para escena {$sceneId}");

        $sceneState = ContinuitySceneState::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->first();

        return [
            ...$active,
            'objective' => $sceneState?->objective,
            'constraints' => $sceneState?->constraints,
            'draft' => $sceneState?->draft,
        ];
    }

    public function createCommitFromCurrentState(array $input): array
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $parentCommitId = ($input['parentCommitId'] ?? null) ? (string) $input['parentCommitId'] : null;
        $sourceParentCommitId = ($input['sourceParentCommitId'] ?? null) ? (string) $input['sourceParentCommitId'] : null;
        $turnIndex = isset($input['turnIndex']) ? (int) $input['turnIndex'] : null;
        $mode = trim((string) ($input['mode'] ?? 'write_scene')) === 'rewrite_scene' ? 'rewrite_scene' : 'write_scene';
        $message = trim((string) ($input['message'] ?? ''));

        $commitId = null;

        DB::transaction(function () use (
            $continuityId,
            $sceneId,
            $parentCommitId,
            $sourceParentCommitId,
            $turnIndex,
            $mode,
            $message,
            &$commitId
        ): void {
            $commit = ContinuityCommit::query()->create([
                'continuity_id' => $continuityId,
                'activity_id' => $sceneId,
                'parent_commit_id' => $parentCommitId,
                'source_parent_commit_id' => $sourceParentCommitId,
                'turn_index' => $turnIndex,
                'mode' => $mode,
                'message' => $message,
            ]);

            $sceneState = ContinuitySceneState::query()
                ->where('continuity_id', $continuityId)
                ->where('activity_id', $sceneId)
                ->first();

            if (! $sceneState) {
                throw new RuntimeException("scene_state no encontrado para continuidad={$continuityId} escena={$sceneId}");
            }

            ContinuityCommitSceneState::query()->updateOrCreate(
                [
                    'commit_id' => $commit->id,
                    'activity_id' => $sceneId,
                ],
                [
                    'continuity_id' => $continuityId,
                    'objective' => $sceneState->objective,
                    'constraints' => $sceneState->constraints,
                    'draft' => $sceneState->draft,
                ]
            );

            SceneActiveContinuity::query()->updateOrCreate(
                ['activity_id' => $sceneId],
                [
                    'continuity_id' => $continuityId,
                    'continuity_commit_id' => $commit->id,
                ]
            );

            $commitId = $commit->id;
        });

        return $this->requireCommitById((string) $commitId);
    }

    public function createBranchFromCommit(array $input): array
    {
        $newContinuityId = trim((string) ($input['newContinuityId'] ?? ''));
        $parentContinuityId = trim((string) ($input['parentContinuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $commitId = trim((string) ($input['commitId'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));

        if ($newContinuityId === '' || $parentContinuityId === '' || $sceneId === '' || $commitId === '') {
            throw new RuntimeException('createBranchFromCommit requiere newContinuityId, parentContinuityId, sceneId y commitId');
        }

        $parent = Continuity::query()->find($parentContinuityId);
        if (! $parent) {
            throw new RuntimeException("continuidad no encontrada: {$parentContinuityId}");
        }

        $sourceCommit = ContinuityCommit::query()->find($commitId);
        if (! $sourceCommit) {
            throw new RuntimeException("commit no encontrado: {$commitId}");
        }
        if ($sourceCommit->continuity_id !== $parentContinuityId) {
            throw new RuntimeException("commit {$commitId} no pertenece a continuidad padre {$parentContinuityId}");
        }
        if ($sourceCommit->activity_id !== $sceneId) {
            throw new RuntimeException("commit {$commitId} no pertenece a escena {$sceneId}");
        }

        $snapshot = ContinuityCommitSceneState::query()
            ->where('commit_id', $commitId)
            ->where('activity_id', $sceneId)
            ->first();

        if (! $snapshot) {
            throw new RuntimeException("snapshot de escena no encontrado en commit {$commitId}");
        }

        $newHeadCommitId = null;

        DB::transaction(function () use (
            $newContinuityId,
            $parentContinuityId,
            $sceneId,
            $commitId,
            $label,
            $parent,
            $sourceCommit,
            $snapshot,
            &$newHeadCommitId
        ): void {
            Continuity::query()->create([
                'id' => $newContinuityId,
                'parent_id' => $parentContinuityId,
                'root_id' => $parent->root_id ?: $parent->id,
                'label' => $label !== '' ? $label : "{$newContinuityId} (branch {$parentContinuityId}@{$commitId})",
                'status' => 'active',
            ]);

            ContinuitySceneState::query()->create([
                'continuity_id' => $newContinuityId,
                'activity_id' => $sceneId,
                'objective' => $snapshot->objective,
                'constraints' => $snapshot->constraints,
                'draft' => $snapshot->draft,
            ]);

            $newCommit = ContinuityCommit::query()->create([
                'continuity_id' => $newContinuityId,
                'activity_id' => $sceneId,
                'parent_commit_id' => null,
                'source_parent_commit_id' => $commitId,
                'turn_index' => $sourceCommit->turn_index,
                'mode' => $sourceCommit->mode,
                'message' => "branch from {$parentContinuityId}@{$commitId}",
            ]);

            ContinuityCommitSceneState::query()->create([
                'commit_id' => $newCommit->id,
                'continuity_id' => $newContinuityId,
                'activity_id' => $sceneId,
                'objective' => $snapshot->objective,
                'constraints' => $snapshot->constraints,
                'draft' => $snapshot->draft,
            ]);

            SceneActiveContinuity::query()->updateOrCreate(
                ['activity_id' => $sceneId],
                [
                    'continuity_id' => $newContinuityId,
                    'continuity_commit_id' => $newCommit->id,
                ]
            );

            $newHeadCommitId = $newCommit->id;
        });

        return [
            ...$this->requireById($newContinuityId),
            'head_commit_id' => $newHeadCommitId,
        ];
    }

    public function setSceneActiveContinuity(array $input): void
    {
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $continuityId = trim((string) ($input['continuityId'] ?? ''));

        if ($sceneId === '' || $continuityId === '') {
            throw new RuntimeException('setSceneActiveContinuity requiere sceneId y continuityId');
        }

        $latestCommitId = ContinuityCommit::query()
            ->where('continuity_id', $continuityId)
            ->where('activity_id', $sceneId)
            ->max('id');

        SceneActiveContinuity::query()->updateOrCreate(
            ['activity_id' => $sceneId],
            [
                'continuity_id' => $continuityId,
                'continuity_commit_id' => $latestCommitId ?: null,
            ]
        );
    }

    public function getActiveSceneContinuity(string $sceneId): ?array
    {
        $record = SceneActiveContinuity::query()->find($sceneId);

        if (! $record) {
            return null;
        }

        return [
            'activity_id' => $record->activity_id,
            'continuity_id' => $record->continuity_id,
            'continuity_commit_id' => $record->continuity_commit_id,
            'updated_at' => optional($record->updated_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapContinuity(Continuity $record): array
    {
        return [
            'id' => $record->id,
            'parent_id' => $record->parent_id,
            'root_id' => $record->root_id,
            'label' => $record->label,
            'status' => $record->status,
            'archived_at' => optional($record->archived_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCommit(ContinuityCommit $record): array
    {
        return [
            'id' => $record->id,
            'continuity_id' => $record->continuity_id,
            'activity_id' => $record->activity_id,
            'parent_commit_id' => $record->parent_commit_id,
            'source_parent_commit_id' => $record->source_parent_commit_id,
            'turn_index' => $record->turn_index,
            'mode' => $record->mode,
            'message' => $record->message,
            'created_at' => optional($record->created_at)?->toISOString(),
        ];
    }
}
