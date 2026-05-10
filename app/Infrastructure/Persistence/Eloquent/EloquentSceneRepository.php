<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\SceneRepository;
use App\Domain\Scene\Activity;
use App\Domain\Scene\SceneCharacter;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;
use Illuminate\Support\Facades\DB;

class EloquentSceneRepository implements SceneRepository
{
    public function save(Activity $scene): void
    {
        DB::transaction(function () use ($scene): void {
            $record = SceneRecord::query()->updateOrCreate(
                ['id' => $scene->id],
                [
                    'vault_id' => $scene->vaultId,
                    'title' => $scene->title,
                    'chapter' => $scene->chapter,
                    'scene_number' => $scene->sceneNumber,
                    'status' => $scene->status,
                    'location_id' => $scene->locationId,
                    'objective' => $scene->objective,
                    'constraints' => $scene->constraints,
                    'draft' => $scene->draft,
                ]
            );

            $sync = [];
            foreach ($scene->characters as $character) {
                $sync[$character->characterId] = ['role' => $character->role];
            }

            $record->characters()->sync($sync);
        });
    }

    public function findById(string $id): ?Activity
    {
        $record = SceneRecord::query()->with('characters')->find($id);

        if (! $record) {
            return null;
        }

        return new Activity(
            id: $record->id,
            vaultId: $record->vault_id,
            title: $record->title,
            chapter: $record->chapter,
            sceneNumber: $record->scene_number,
            status: $record->status,
            locationId: $record->location_id,
            objective: $record->objective,
            constraints: $record->constraints,
            draft: $record->draft ?? '',
            characters: $record->characters
                ->map(fn ($character): SceneCharacter => new SceneCharacter(
                    characterId: $character->id,
                    role: $character->pivot->role,
                ))
                ->values()
                ->all(),
        );
    }
}
