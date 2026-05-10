<?php

namespace App\Domains\Community\Repositories;

use App\Domains\Community\Contracts\PlayerRepositoryInterface;
use App\Domains\Community\Models\Player;
use Illuminate\Support\Facades\Log;

class EloquentPlayerRepository implements PlayerRepositoryInterface
{
    public function findByDiscordId(string $discordId): ?Player
    {
        Log::debug('[EloquentPlayerRepository@findByDiscordId]', ['discord_id' => $discordId]);

        return Player::where('discord_id', $discordId)->first();
    }

    public function findOrCreateByDiscordId(string $discordId, array $attributes = []): Player
    {
        Log::debug('[EloquentPlayerRepository@findOrCreateByDiscordId]', ['discord_id' => $discordId]);

        return Player::firstOrCreate(['discord_id' => $discordId], $attributes);
    }

    public function save(Player $player): void
    {
        $player->save();
    }
}
