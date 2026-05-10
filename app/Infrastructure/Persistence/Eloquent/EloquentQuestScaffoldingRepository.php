<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\QuestScaffoldingRepository;
use App\Models\Quest;
use App\Models\QuestStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EloquentQuestScaffoldingRepository implements QuestScaffoldingRepository
{
    public function findForBootstrap(string $vaultId, string $questId): ?array
    {
        $quest = Quest::query()
            ->with(['steps' => fn ($query) => $query->orderBy('stage_number')])
            ->where('vault_id', $vaultId)
            ->find($questId);

        if (! $quest) {
            return null;
        }

        $firstStep = $quest->steps->first();

        return [
            'questId' => (string) $quest->id,
            'title' => (string) $quest->title,
            'description' => (string) $quest->description,
            'type' => (string) $quest->type,
            'status' => (string) $quest->status,
            'steps' => $quest->steps->map(fn (QuestStep $step): array => [
                'stage_number' => (int) $step->stage_number,
                'description' => (string) $step->description,
                'is_optional' => (bool) $step->is_optional,
            ])->values()->all(),
            'firstStageNumber' => $firstStep ? (int) $firstStep->stage_number : null,
            'currentObjective' => $firstStep ? (string) $firstStep->description : (string) $quest->title,
            'generated' => false,
        ];
    }

    public function saveGeneratedQuest(string $vaultId, array $scaffold): array
    {
        return DB::transaction(function () use ($vaultId, $scaffold): array {
            $title = trim((string) ($scaffold['title'] ?? 'Quest base'));
            $questId = $this->uniqueQuestId($vaultId, $title);
            $description = trim((string) ($scaffold['description'] ?? $title));
            $type = trim((string) ($scaffold['type'] ?? 'main')) ?: 'main';
            $status = trim((string) ($scaffold['status'] ?? 'active')) ?: 'active';
            $steps = collect(is_array($scaffold['steps'] ?? null) ? $scaffold['steps'] : [])
                ->filter(fn ($step) => is_array($step) && trim((string) ($step['description'] ?? '')) !== '')
                ->sortBy(fn ($step) => (int) ($step['stage_number'] ?? 0))
                ->values();

            $quest = Quest::query()->create([
                'id' => $questId,
                'vault_id' => $vaultId,
                'title' => $title,
                'description' => $description,
                'type' => $type,
                'status' => $status,
            ]);

            foreach ($steps as $step) {
                QuestStep::query()->create([
                    'quest_id' => $quest->id,
                    'stage_number' => (int) $step['stage_number'],
                    'description' => trim((string) $step['description']),
                    'is_optional' => (bool) ($step['is_optional'] ?? false),
                ]);
            }

            /** @var QuestStep|null $firstStep */
            $firstStep = QuestStep::query()
                ->where('quest_id', $quest->id)
                ->orderBy('stage_number')
                ->first();

            return [
                'questId' => (string) $quest->id,
                'title' => (string) $quest->title,
                'description' => (string) $quest->description,
                'type' => (string) $quest->type,
                'status' => (string) $quest->status,
                'steps' => $steps->values()->all(),
                'firstStageNumber' => $firstStep ? (int) $firstStep->stage_number : null,
                'currentObjective' => $firstStep ? (string) $firstStep->description : (string) $quest->title,
                'generated' => true,
            ];
        });
    }

    private function uniqueQuestId(string $vaultId, string $title): string
    {
        $base = Str::of($title)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
        $base = $base !== '' ? $base : 'quest';
        $candidate = $base;
        $suffix = 1;

        while (Quest::query()->where('vault_id', $vaultId)->where('id', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'_'.$suffix;
        }

        return $candidate;
    }
}
