<?php

declare(strict_types=1);

namespace App\Application\UseCases\Character;

use App\Application\Ports\CharacterRepositoryInterface;

final class UpsertCharacterProfileUseCase
{
    public function __construct(
        private readonly CharacterRepositoryInterface $characterRepository
    ) {
    }

    public function execute(UpsertCharacterProfileInput $input): array
    {
        $profile = [
            'id' => $input->id,
            'name' => $input->name,
            'context_id' => $input->context_id,
            'tags' => $input->tags,
            'traits' => $input->traits,
            'vault_id' => $input->vault_id,
        ];

        $result = $this->characterRepository->upsertCharacterProfile($profile);

        return [
            'characterId' => $input->id,
            'contextId' => $input->context_id,
            'stats' => $result['stats'] ?? null,
        ];
    }
}
