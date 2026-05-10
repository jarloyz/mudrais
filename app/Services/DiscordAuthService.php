<?php

namespace App\Services;

use App\Jobs\SyncPlayerQdrantGuildsJob;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\Uuid;

class DiscordAuthService
{
    /**
     * Authenticate or register a player from Discord data.
     */
    public function authenticate(string $discordId, string $username, ?string $guildId = null): Player
    {
        $player = Player::updateOrCreate(
            ['discord_id' => $discordId],
            [
                'username' => $username,
                'last_active_at' => Carbon::now(),
            ]
        );

        if ($player->wasRecentlyCreated) {
            Log::info("Nuevo jugador registrado vía Discord: {$username} ({$discordId})");
        }

        if ($guildId) {
            $guild = \App\Domains\Community\Models\Guild::where('discord_guild_id', $guildId)->first();

            if ($guild) {
                $inserted = DB::table('guild_members')->insertOrIgnore([
                    'id' => (string) Uuid::v7(),
                    'player_id' => $player->id,
                    'guild_id' => $guild->id,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted > 0) {
                    Log::debug("DiscordAuthService: nuevo guild registrado.", [
                        'player_id' => $player->id,
                        'guild_id'  => $guild->id,
                    ]);

                    SyncPlayerQdrantGuildsJob::dispatch($player->id);
                }
            } else {
                Log::warning("DiscordAuthService: Guild no encontrada para el snowflake provisto.", [
                    'discord_guild_id' => $guildId,
                    'player_id'        => $player->id,
                ]);
            }
        }

        return $player;
    }
}
