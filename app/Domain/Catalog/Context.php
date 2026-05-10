<?php

namespace App\Domain\Catalog;

final readonly class Context
{
    public function __construct(
        public string $id,
        public string $label,
        public ?int $legacyContextId = null,
    ) {
    }
}
