<?php

namespace App\Domain\Catalog;

final readonly class AvatarTrait
{
    /**
     * @param array<int, AvatarBullet> $bullets
     */
    public function __construct(
        public string $key,
        public string $title,
        public int $sortOrder = 0,
        public ?string $contextId = null,
        public array $bullets = [],
    ) {
    }
}
