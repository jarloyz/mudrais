<?php

namespace App\Domain\Catalog;

final readonly class Location
{
    public function __construct(
        public string $id,
        public string $vaultId,
        public string $name,
        public ?string $contextId = null,
    ) {
    }
}
