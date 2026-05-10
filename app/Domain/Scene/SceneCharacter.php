<?php

namespace App\Domain\Scene;

final readonly class SceneCharacter
{
    public function __construct(
        public string $characterId,
        public ?string $role = null,
    ) {
    }
}
