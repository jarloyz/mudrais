<?php

namespace Tests\Feature\Auth;

use App\Domains\Community\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;
use Mockery;

class DiscordOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_to_discord()
    {
        $response = $this->get('/auth/discord/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('discord.com', $response->headers->get('Location'));
    }

    public function test_callback_creates_player_and_starts_session()
    {
        $discordId = '123456789';
        $nickname  = 'TestPlayer';

        $socialiteUser = Mockery::mock(\Laravel\Socialite\Two\User::class);
        $socialiteUser->shouldReceive('getId')->andReturn($discordId);
        $socialiteUser->shouldReceive('getNickname')->andReturn($nickname);
        $socialiteUser->shouldReceive('getName')->andReturn('RealName');

        $provider = Mockery::mock(\Laravel\Socialite\Two\AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

        $response = $this->get('/auth/discord/callback');

        $response->assertRedirect(route('filament.player.pages.discord-dashboard'));

        $player = Player::where('discord_id', $discordId)->firstOrFail();
        $this->assertAuthenticatedAs($player, 'player_web');

        $this->assertDatabaseHas('players', [
            'discord_id' => $discordId,
            'username'   => $nickname,
        ]);
    }

    public function test_callback_updates_existing_player_username()
    {
        $discordId = '987654321';
        $player = Player::create([
            'discord_id'     => $discordId,
            'username'       => 'OldName',
            'last_active_at' => now(),
        ]);

        $socialiteUser = Mockery::mock(\Laravel\Socialite\Two\User::class);
        $socialiteUser->shouldReceive('getId')->andReturn($discordId);
        $socialiteUser->shouldReceive('getNickname')->andReturn('NewName');
        $socialiteUser->shouldReceive('getName')->andReturn('RealName');

        $provider = Mockery::mock(\Laravel\Socialite\Two\AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

        $response = $this->get('/auth/discord/callback');

        $response->assertRedirect(route('filament.player.pages.discord-dashboard'));
        $this->assertAuthenticatedAs($player->fresh(), 'player_web');

        $this->assertDatabaseHas('players', [
            'id'       => $player->id,
            'username' => 'NewName',
        ]);
    }

    public function test_unauthenticated_player_is_redirected_to_discord_login()
    {
        // Filament redirige al login del panel (/player/login),
        // que a su vez redirige a Discord OAuth.
        $response = $this->get('/player/discord-dashboard');

        $response->assertRedirect();
        $this->assertStringContainsString('/player/login', $response->headers->get('Location'));
    }
}
