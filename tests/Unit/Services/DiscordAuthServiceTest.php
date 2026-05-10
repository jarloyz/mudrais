<?php

namespace Tests\Unit\Services;

use App\Models\Player;
use App\Services\DiscordAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscordAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_new_player()
    {
        $service = new DiscordAuthService();
        $discordId = '123456789';
        $username = 'TestUser';

        $player = $service->authenticate($discordId, $username);

        $this->assertInstanceOf(Player::class, $player);
        $this->assertEquals($discordId, $player->discord_id);
        $this->assertEquals($username, $player->username);
        $this->assertEquals(100, $player->energy);
        $this->assertEquals(1000, $player->elo);
        $this->assertDatabaseHas('players', ['discord_id' => $discordId]);
    }

    public function test_it_authenticates_an_existing_player_and_updates_username()
    {
        $discordId = '123456789';
        Player::create([
            'discord_id' => $discordId,
            'username' => 'OldUsername',
            'energy' => 50,
            'elo' => 1200
        ]);

        $service = new DiscordAuthService();
        $newUsername = 'NewUsername';

        $player = $service->authenticate($discordId, $newUsername);

        $this->assertEquals($newUsername, $player->username);
        $this->assertEquals(50, $player->energy); // Should persist existing values
        $this->assertEquals(1200, $player->elo);
        $this->assertNotNull($player->last_active_at);
    }
}
