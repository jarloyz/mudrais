<?php

namespace App\Domain\Catalog;

final readonly class AvatarBullet
{
    public function __construct(
        public string $body,
        public ?string $section = null,
        public int $sortOrder = 0,
        public ?int $legacyBulletId = null,
        public ?int $parentLegacyBulletId = null,
    ) {
    }
}
