<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\CharacterRuntimeStatusRepository;
use App\Models\CharacterRuntimeStatus;
use Illuminate\Support\Facades\DB;

class EloquentCharacterRuntimeStatusRepository implements CharacterRuntimeStatusRepository
{
    public function upsertManyStatus(array $input): array
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = isset($input['sceneId']) ? trim((string) $input['sceneId']) ?: null : null;
        $userId = isset($input['userId']) ? (int) $input['userId'] : null;
        $source = trim((string) ($input['source'] ?? 'system')) ?: 'system';
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        $written = 0;

        DB::transaction(function () use ($rows, $continuityId, $sceneId, $userId, $source, &$written): void {
            foreach ($rows as $row) {
                $characterId = trim((string) ($row['character_id'] ?? ''));
                $statusKey = trim((string) ($row['status_key'] ?? ''));
                if ($characterId === '' || $statusKey === '') {
                    continue;
                }

                $query = CharacterRuntimeStatus::query()
                    ->where('continuity_id', $continuityId)
                    ->where('character_id', $characterId)
                    ->where('status_key', $statusKey);

                if ($sceneId === null) {
                    $query->whereNull('activity_id');
                } else {
                    $query->where('activity_id', $sceneId);
                }

                if ($userId === null) {
                    $query->whereNull('player_id');
                } else {
                    $query->where('player_id', $userId);
                }

                $values = [
                    'status_value' => $row['status_value'] ?? null,
                    'status_text' => $row['status_text'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'source' => trim((string) ($row['source'] ?? $source)) ?: $source,
                ];

                $existing = $query->first();
                if ($existing !== null) {
                    $existing->fill($values)->save();
                } else {
                    CharacterRuntimeStatus::create(array_merge([
                        'continuity_id' => $continuityId,
                        'activity_id' => $sceneId,
                        'player_id' => $userId,
                        'character_id' => $characterId,
                        'status_key' => $statusKey,
                    ], $values));
                }

                $written++;
            }
        });

        return ['written' => $written];
    }

    public function listForSceneContext(string $continuityId, ?string $sceneId, ?string $userId, array $characterIds): array
    {
        if ($continuityId === '' || $characterIds === []) {
            return [];
        }

        $query = CharacterRuntimeStatus::query()
            ->where('continuity_id', $continuityId)
            ->whereIn('character_id', $characterIds)
            ->orderBy('character_id')
            ->orderBy('status_key');

        if ($sceneId !== null) {
            $query->where('activity_id', $sceneId);
        } else {
            $query->whereNull('activity_id');
        }

        if ($userId !== null) {
            $query->where(function ($builder) use ($userId): void {
                $builder->where('player_id', $userId)->orWhereNull('player_id');
            });
        } else {
            $query->whereNull('player_id');
        }

        $grouped = [];
        foreach ($query->get() as $row) {
            $grouped[$row->character_id] ??= [];
            $grouped[$row->character_id][] = [
                'status_key' => $row->status_key,
                'status_value' => $row->status_value,
                'status_text' => $row->status_text,
                'unit' => $row->unit,
                'source' => $row->source,
            ];
        }

        return $grouped;
    }
}
