<?php

namespace App\Domains\Narrative\Infrastructure;

use App\Domains\Narrative\Contracts\SceneRepositoryInterface;
use App\Domains\Narrative\Models\Activity;
use Illuminate\Support\Facades\Log;

class EloquentSceneRepository implements SceneRepositoryInterface
{
    public function findById(string $sceneId): ?Activity
    {
        Log::debug('[EloquentSceneRepository@findById]', ['scene_id' => $sceneId]);

        return Activity::find($sceneId);
    }

    public function save(Activity $scene): void
    {
        $scene->save();
    }

    public function findWithContext(string $sceneId): ?Activity
    {
        Log::debug('[EloquentSceneRepository@findWithContext]', ['scene_id' => $sceneId]);

        return Activity::with([
            'vault',
            'location',
            'characters',
            'activeContinuity',
        ])->find($sceneId);
    }
}
