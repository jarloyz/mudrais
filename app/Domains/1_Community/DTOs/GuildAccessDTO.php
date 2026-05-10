<?php

namespace App\Domains\Community\DTOs;

final class GuildAccessDTO
{
    public function __construct(
        public readonly string $discordGuildId,
        public readonly string $discordUserId,
        public readonly bool $hasAccess,
        public readonly ?string $reason = null,
    ) {
    }
}
