<?php

namespace App\Infrastructure\Persistence\Cache;

use App\Application\Contracts\SceneCacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class LaravelSceneCacheRepository implements SceneCacheRepository
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function get(string $bucket, string $sceneKey): ?array
    {
        $payload = $this->cache->get($this->buildKey($bucket, $sceneKey));

        return is_array($payload) ? $payload : null;
    }

    public function put(string $bucket, string $sceneKey, array $payload): void
    {
        $ttl = max(1, (int) config('historia.cache.scene_ttl_seconds', 3600));

        $this->cache->put($this->buildKey($bucket, $sceneKey), $payload, now()->addSeconds($ttl));
    }

    private function buildKey(string $bucket, string $sceneKey): string
    {
        $safeSceneKey = preg_replace('/[^a-zA-Z0-9._:-]+/', '_', $sceneKey) ?: 'scene';

        return trim((string) config('historia.cache.prefix', 'historia'), ':').":{$bucket}:{$safeSceneKey}";
    }
}
