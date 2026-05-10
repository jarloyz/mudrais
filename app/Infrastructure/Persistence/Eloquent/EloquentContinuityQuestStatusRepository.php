<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\ContinuityQuestStatusRepository;
use App\Models\ContinuityQuestStatus;
use App\Models\Quest;

class EloquentContinuityQuestStatusRepository implements ContinuityQuestStatusRepository
{
    public function listForSceneContext(string $continuityId, string $vaultId): array
    {
        return ContinuityQuestStatus::query()
            ->with([
                'quest.steps' => fn ($query) => $query->orderBy('stage_number'),
            ])
            ->where('continuity_id', $continuityId)
            ->where('status', '!=', 'hidden')
            ->whereHas('quest', fn ($query) => $query->where('vault_id', $vaultId))
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (ContinuityQuestStatus $status): array {
                $quest = $status->quest;
                $currentStep = $quest?->steps
                    ?->firstWhere('stage_number', (int) $status->current_stage_number);

                return [
                    'quest_id' => (string) $status->quest_id,
                    'title' => trim((string) ($quest?->title ?? '')),
                    'type' => trim((string) ($quest?->type ?? '')),
                    'status' => trim((string) ($status->status ?? 'active')),
                    'current_stage_number' => (int) ($status->current_stage_number ?? 0),
                    'current_step' => $currentStep
                        ? [
                            'stage_number' => (int) $currentStep->stage_number,
                            'description' => trim((string) $currentStep->description),
                            'is_optional' => (bool) $currentStep->is_optional,
                        ]
                        : null,
                    'ai_summary' => $status->ai_summary ? trim((string) $status->ai_summary) : null,
                ];
            })
            ->values()
            ->all();
    }

    public function applyDirective(string $continuityId, ?string $sceneId, array $directive): array
    {
        $questId = trim((string) ($directive['quest_id'] ?? ''));
        $newStatus = trim((string) ($directive['new_status'] ?? ''));
        $newStageNumber = array_key_exists('new_stage_number', $directive)
            ? (int) $directive['new_stage_number']
            : null;

        if ($questId === '') {
            return [
                'applied' => false,
                'reason' => 'missing_quest_id',
            ];
        }

        $record = ContinuityQuestStatus::query()->firstOrNew([
            'continuity_id' => $continuityId,
            'quest_id' => $questId,
        ]);

        if (! $record->exists) {
            $record->status = $newStatus !== '' ? $newStatus : 'active';
            $record->current_stage_number = $newStageNumber ?? 0;
        }

        if ($sceneId !== null && $sceneId !== '') {
            $record->activity_id = $sceneId;
        }
        if ($newStatus !== '') {
            $record->status = $newStatus;
        }
        if ($newStageNumber !== null) {
            $record->current_stage_number = $newStageNumber;
        }
        if (is_string($directive['ai_summary'] ?? null)) {
            $record->ai_summary = trim((string) $directive['ai_summary']) ?: null;
        }

        $record->save();

        return [
            'applied' => true,
            'questId' => $questId,
            'status' => (string) $record->status,
            'currentStageNumber' => (int) $record->current_stage_number,
        ];
    }

    public function getTransitionContext(string $continuityId, string $questId): array
    {
        $questId = trim($questId);
        $quest = Quest::query()
            ->with(['steps' => fn ($query) => $query->orderBy('stage_number')])
            ->find($questId);

        if (! $quest) {
            return [
                'questExists' => false,
                'questId' => $questId,
                'currentStatus' => null,
                'currentStageNumber' => null,
                'validStageNumbers' => [],
                'nextStageNumber' => null,
                'lastStageNumber' => null,
            ];
        }

        $status = ContinuityQuestStatus::query()
            ->where('continuity_id', $continuityId)
            ->where('quest_id', $questId)
            ->first();

        $validStageNumbers = $quest->steps
            ->pluck('stage_number')
            ->map(static fn ($stage): int => (int) $stage)
            ->values()
            ->all();
        $currentStageNumber = $status ? (int) $status->current_stage_number : null;
        $nextStageNumber = null;

        if ($currentStageNumber !== null) {
            foreach ($validStageNumbers as $stageNumber) {
                if ($stageNumber > $currentStageNumber) {
                    $nextStageNumber = $stageNumber;
                    break;
                }
            }
        } elseif ($validStageNumbers !== []) {
            $nextStageNumber = $validStageNumbers[0];
        }

        return [
            'questExists' => true,
            'questId' => $questId,
            'currentStatus' => $status ? (string) $status->status : null,
            'currentStageNumber' => $currentStageNumber,
            'validStageNumbers' => $validStageNumbers,
            'nextStageNumber' => $nextStageNumber,
            'lastStageNumber' => $validStageNumbers !== [] ? $validStageNumbers[array_key_last($validStageNumbers)] : null,
        ];
    }
}
