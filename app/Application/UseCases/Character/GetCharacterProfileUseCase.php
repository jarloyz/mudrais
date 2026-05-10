<?php

declare(strict_types=1);

namespace App\Application\UseCases\Character;

use App\Application\Ports\CharacterRepositoryInterface;

final class GetCharacterProfileUseCase
{
    public function __construct(
        private readonly CharacterRepositoryInterface $characterRepository
    ) {
    }

    public function execute(GetCharacterProfileInput $input): array
    {
        return $this->characterRepository->getCharacterProfile(
            $input->characterId,
            $input->contextId
        );
    }
}
