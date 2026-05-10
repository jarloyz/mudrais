<?php

namespace App\Domains\Community\Contracts;

use App\Domains\Community\Models\Guild;

interface GuildRepositoryInterface
{
    public function findByDiscordGuildId(string $discordGuildId): ?Guild;

    public function findOrCreate(string $discordGuildId, int $archetypeId): Guild;

    public function hasQuotaAvailable(Guild $guild): bool;
}
