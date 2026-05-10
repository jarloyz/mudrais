<?php

namespace App\Application\Contracts;

interface SceneCacheRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $bucket, string $sceneKey): ?array;

    /**
     * @param array<string, mixed> $payload
     */
    public function put(string $bucket, string $sceneKey, array $payload): void;
}
