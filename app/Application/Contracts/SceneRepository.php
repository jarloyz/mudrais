<?php

namespace App\Application\Contracts;

use App\Domain\Scene\Activity;

interface SceneRepository
{
    public function save(Activity $scene): void;

    public function findById(string $id): ?Activity;
}
