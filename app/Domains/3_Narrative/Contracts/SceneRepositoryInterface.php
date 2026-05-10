<?php

namespace App\Domains\Narrative\Contracts;

use App\Domains\Narrative\Models\Activity;

interface SceneRepositoryInterface
{
    public function findById(string $sceneId): ?Activity;

    public function save(Activity $scene): void;

    /**
     * Carga la escena con su contexto completo (personajes, continuidad, lore).
     */
    public function findWithContext(string $sceneId): ?Activity;
}
