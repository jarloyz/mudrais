<?php

namespace App\Application\Contracts;

use App\Domain\Catalog\Avatar;

interface CharacterRepository
{
    public function save(Avatar $avatar): void;

    public function findById(string $id): ?Avatar;
}
