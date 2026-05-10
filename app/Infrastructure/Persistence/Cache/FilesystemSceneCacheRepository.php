<?php

namespace App\Infrastructure\Persistence\Cache;

use App\Application\Contracts\SceneCacheRepository;
use Illuminate\Filesystem\Filesystem;

class FilesystemSceneCacheRepository implements SceneCacheRepository
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    public function get(string $bucket, string $sceneKey): ?array
    {
        $path = $this->buildPath($bucket, $sceneKey);
        if (! $this->filesystem->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $this->filesystem->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $bucket, string $sceneKey, array $payload): void
    {
        $path = $this->buildPath($bucket, $sceneKey);
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function buildPath(string $bucket, string $sceneKey): string
    {
        $safeSceneKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $sceneKey) ?: 'scene';

        return rtrim((string) config('historia.cache.root'), '/')."/{$bucket}/{$safeSceneKey}.json";
    }
}
