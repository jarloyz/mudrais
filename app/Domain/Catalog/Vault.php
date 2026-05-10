<?php

namespace App\Domain\Catalog;

final readonly class Vault
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status = 'active',
        public ?string $description = null,
    ) {
    }
}
