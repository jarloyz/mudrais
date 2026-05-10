<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\LocationRepository;
use App\Domain\Catalog\Location;
use App\Infrastructure\Persistence\Eloquent\Models\LocationRecord;

class EloquentLocationRepository implements LocationRepository
{
    public function save(Location $location): void
    {
        LocationRecord::query()->updateOrCreate(
            ['id' => $location->id],
            [
                'vault_id' => $location->vaultId,
                'name' => $location->name,
                'context_id' => $location->contextId,
            ]
        );
    }

    public function findById(string $id): ?Location
    {
        $record = LocationRecord::query()->find($id);

        return $record
            ? new Location(
                id: $record->id,
                vaultId: $record->vault_id,
                name: $record->name,
                contextId: $record->context_id,
            )
            : null;
    }
}
