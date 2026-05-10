<?php

namespace App\Domain\Catalog;

final readonly class Avatar
{
    /**
     * @param array<int, CharacterTrait> $traits
     */
    public function __construct(
        public string $id,
        public string $vaultId,
        public string $name,
        public array $traits = [],
    ) {
    }
}
