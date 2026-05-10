<?php

namespace Tests\Unit\Models;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildMember;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerTest extends TestCase
{
    use RefreshDatabase;

    private function makePlayer(array $attrs = []): Player
    {
        return Player::create(array_merge([
            'discord_id' => (string) fake()->unique()->numerify('##########'),
            'username'   => fake()->userName(),
            'energy'     => 100,
            'coin'       => 0,
            'elo'        => 1000,
            'is_active'  => true,
        ], $attrs));
    }

    private function makeGuild(array $attrs = []): Guild
    {
        $archetype = Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto']
        );
        return Guild::create(array_merge([
            'discord_guild_id' => 'guild_' . uniqid(),
            'archetype_id'     => $archetype->id,
            'is_active'        => true,
        ], $attrs));
    }

    private function attachPlayerToGuild(Player $player, Guild $guild, string $role = 'player'): void
    {
        GuildMember::create([
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
            'role'      => $role,
        ]);
    }

    public function test_is_admin_in_returns_true_when_player_is_admin(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();
        $this->attachPlayerToGuild($player, $guild, 'admin');

        $this->assertTrue($player->isAdminIn($guild->discord_guild_id));
    }

    public function test_is_admin_in_returns_false_when_player_is_moderator(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();
        $this->attachPlayerToGuild($player, $guild, 'moderator');

        $this->assertFalse($player->isAdminIn($guild->discord_guild_id));
    }

    public function test_is_admin_in_returns_false_when_player_is_regular_player(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();
        $this->attachPlayerToGuild($player, $guild, 'player');

        $this->assertFalse($player->isAdminIn($guild->discord_guild_id));
    }

    public function test_is_admin_in_returns_false_when_not_member_of_guild(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();

        $this->assertFalse($player->isAdminIn($guild->discord_guild_id));
    }

    public function test_is_admin_in_is_scoped_to_specific_guild(): void
    {
        $player = $this->makePlayer();
        $guild1 = $this->makeGuild();
        $guild2 = $this->makeGuild();
        $this->attachPlayerToGuild($player, $guild1, 'admin');
        $this->attachPlayerToGuild($player, $guild2, 'player');

        $this->assertTrue($player->isAdminIn($guild1->discord_guild_id));
        $this->assertFalse($player->isAdminIn($guild2->discord_guild_id));
    }

    public function test_get_role_in_returns_correct_role(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();
        $this->attachPlayerToGuild($player, $guild, 'moderator');

        $this->assertEquals('moderator', $player->getRoleIn($guild->discord_guild_id));
    }

    public function test_get_role_in_returns_null_when_not_member(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();

        $this->assertNull($player->getRoleIn($guild->discord_guild_id));
    }

    public function test_get_role_in_is_scoped_to_specific_guild(): void
    {
        $player = $this->makePlayer();
        $guild1 = $this->makeGuild();
        $guild2 = $this->makeGuild();
        $this->attachPlayerToGuild($player, $guild1, 'admin');
        $this->attachPlayerToGuild($player, $guild2, 'player');

        $this->assertEquals('admin', $player->getRoleIn($guild1->discord_guild_id));
        $this->assertEquals('player', $player->getRoleIn($guild2->discord_guild_id));
    }

    public function test_guilds_relation_returns_multiple_guild_memberships(): void
    {
        $player = $this->makePlayer();
        $guild1 = $this->makeGuild();
        $guild2 = $this->makeGuild();
        $this->attachPlayerToGuild($player, $guild1, 'admin');
        $this->attachPlayerToGuild($player, $guild2, 'player');

        $this->assertCount(2, $player->guilds);
    }
}
