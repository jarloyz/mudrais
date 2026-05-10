<?php

namespace App\Domains\Community\Contracts;

use App\Domains\Community\Models\Player;

interface PlayerRepositoryInterface
{
    public function findByDiscordId(string $discordId): ?Player;

    public function findOrCreateByDiscordId(string $discordId, array $attributes = []): Player;

    public function save(Player $player): void;
}
