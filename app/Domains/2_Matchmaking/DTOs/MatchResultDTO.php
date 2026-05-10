<?php

namespace App\Domains\Matchmaking\DTOs;

final class MatchResultDTO
{
    public function __construct(
        public readonly string $discordUserId,
        public readonly float $score,
        public readonly array $metadata = [],
    ) {
    }
}
