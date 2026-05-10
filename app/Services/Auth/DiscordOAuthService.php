<?php

namespace App\Services\Auth;

use App\Domains\Community\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class DiscordOAuthService
{
    public function authenticateOrRegister(SocialiteUser $socialUser): Player
    {
        Log::debug('[DiscordOAuthService] Authenticating or registering player.', ['discord_id' => $socialUser->getId()]);

        try {
            $player = DB::transaction(function () use ($socialUser) {
                return Player::updateOrCreate(
                    ['discord_id' => $socialUser->getId()],
                    [
                        'username' => $socialUser->getNickname() ?? $socialUser->getName(),
                        'last_active_at' => now(),
                    ]
                );
            });

            Log::info('[DiscordOAuthService] Player authenticated successfully.', ['player_id' => $player->id]);
            return $player;
        } catch (\Exception $e) {
            Log::error('[DiscordOAuthService] Exception authenticating player.', [
                'discord_id' => $socialUser->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
