<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Symfony\Component\Uid\Uuid;

trait HasUuidV7
{
    use HasUuids;

    /**
     * Generate a new UUIDv7 for the model.
     */
    public function newUniqueId(): string
    {
        return (string) Uuid::v7();
    }
}
