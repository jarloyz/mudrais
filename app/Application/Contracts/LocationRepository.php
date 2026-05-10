<?php

namespace App\Application\Contracts;

use App\Domain\Catalog\Location;

interface LocationRepository
{
    public function save(Location $location): void;

    public function findById(string $id): ?Location;
}
