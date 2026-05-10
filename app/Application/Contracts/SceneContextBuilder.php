<?php

namespace App\Application\Contracts;

interface SceneContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array;
}
