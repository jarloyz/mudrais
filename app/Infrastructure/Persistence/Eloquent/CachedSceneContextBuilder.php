<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\SceneContextBuilder;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CachedSceneContextBuilder implements SceneContextBuilder
{
    public function __construct(
        private readonly EloquentSceneContextBuilder $inner,
        private readonly CacheRepository $cache,
    ) {
    }

    public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array
    {
        $ttl = max(1, (int) config('historia.cache.context_ttl_seconds', 60));
        $key = $this->buildKey($sceneId, $continuityId, $userId);

        $payload = $this->cache->remember($key, now()->addSeconds($ttl), fn (): array => $this->inner->build($sceneId, $continuityId, $userId));

        return is_array($payload) ? $payload : [];
    }

    private function buildKey(string $sceneId, ?string $continuityId, ?string $userId): string
    {
        $parts = [
            trim((string) config('historia.cache.prefix', 'historia'), ':'),
            'scene_context_builder',
            $sceneId,
            $continuityId !== null && trim($continuityId) !== '' ? $continuityId : 'base',
            $userId !== null ? (string) $userId : 'anon',
        ];

        return implode(':', $parts);
    }
}
