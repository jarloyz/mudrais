<?php

namespace Tests\Unit\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\CachedSceneContextBuilder;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneContextBuilder;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CachedSceneContextBuilderTest extends TestCase
{
    public function test_reuses_cached_scene_context_for_same_key(): void
    {
        Cache::flush();

        $inner = new class extends EloquentSceneContextBuilder
        {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array
            {
                $this->calls++;

                return [
                    'sceneId' => $sceneId,
                    'continuityId' => $continuityId,
                    'userId' => $userId,
                ];
            }
        };

        $builder = new CachedSceneContextBuilder($inner, Cache::store(config('cache.default')));

        $first = $builder->build('scene_1', 'cont_1', 1);
        $second = $builder->build('scene_1', 'cont_1', 1);

        $this->assertSame($first, $second);
        $this->assertSame(1, $inner->calls);
    }
}
