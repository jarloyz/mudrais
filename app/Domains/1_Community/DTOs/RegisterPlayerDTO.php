<?php

namespace App\Domains\Community\DTOs;

final class RegisterPlayerDTO
{
    public function __construct(
        public readonly string $discordId,
        public readonly string $discordGuildId,
        public readonly string $username,
        public readonly ?string $rawProfile = null,
    ) {
    }
}
