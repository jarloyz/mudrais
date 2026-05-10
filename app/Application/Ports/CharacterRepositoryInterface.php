<?php

declare(strict_types=1);

namespace App\Application\Ports;

interface CharacterRepositoryInterface
{
    public function getCharacterProfile(string $characterId, ?int $contextId): array;
    public function upsertCharacterProfile(array $profile): array;
}
