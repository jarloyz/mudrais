<?php

namespace Tests\Unit\Services\Auth;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildMember;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use App\Services\Auth\GuildMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildMembershipServiceTest extends TestCase
{
    use RefreshDatabase;

    private GuildMembershipService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GuildMembershipService();
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

    public function test_join_guild_creates_membership_with_default_role(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();

        $member = $this->service->joinGuild($player, $guild);

        $this->assertInstanceOf(GuildMember::class, $member);
        $this->assertEquals($player->id, $member->player_id);
        $this->assertEquals($guild->id, $member->guild_id);
        $this->assertEquals('player', $member->role);
        $this->assertDatabaseHas('guild_player', [
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
            'role'      => 'player',
        ]);
    }

    public function test_join_guild_creates_membership_with_specified_role(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();

        $member = $this->service->joinGuild($player, $guild, 'admin');

        $this->assertEquals('admin', $member->role);
        $this->assertDatabaseHas('guild_player', [
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
            'role'      => 'admin',
        ]);
    }

    public function test_join_guild_updates_existing_membership(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();

        $this->service->joinGuild($player, $guild, 'player');
        $this->service->joinGuild($player, $guild, 'moderator');

        $this->assertDatabaseCount('guild_player', 1);
        $this->assertDatabaseHas('guild_player', ['role' => 'moderator']);
    }

    public function test_promote_player_updates_role(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();
        $this->service->joinGuild($player, $guild, 'player');

        $member = $this->service->promotePlayer($player, $guild, 'moderator');

        $this->assertEquals('moderator', $member->role);
        $this->assertDatabaseHas('guild_player', [
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
            'role'      => 'moderator',
        ]);
    }

    public function test_promote_player_throws_for_invalid_role(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();
        $this->service->joinGuild($player, $guild, 'player');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->promotePlayer($player, $guild, 'superuser');
    }

    public function test_promote_player_throws_when_not_member(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->promotePlayer($player, $guild, 'admin');
    }

    public function test_resolve_owner_role_assigns_admin_when_player_exists(): void
    {
        $player = $this->makePlayer(['discord_id' => '999000111']);
        $guild  = $this->makeGuild(['owner_discord_id' => '999000111']);

        $this->service->resolveOwnerRole($guild);

        $this->assertDatabaseHas('guild_player', [
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
            'role'      => 'admin',
        ]);
    }

    public function test_resolve_owner_role_does_nothing_when_owner_has_no_player(): void
    {
        $guild = $this->makeGuild(['owner_discord_id' => 'nonexistent_owner']);

        $this->service->resolveOwnerRole($guild);

        $this->assertDatabaseCount('guild_player', 0);
    }

    public function test_resolve_owner_role_does_nothing_when_owner_discord_id_is_null(): void
    {
        $guild = $this->makeGuild(['owner_discord_id' => null]);

        $this->service->resolveOwnerRole($guild);

        $this->assertDatabaseCount('guild_player', 0);
    }

    public function test_get_or_assign_creates_membership_when_none_exists(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();

        $member = $this->service->getOrAssign($player, $guild);

        $this->assertEquals('player', $member->role);
        $this->assertDatabaseHas('guild_player', ['player_id' => $player->id, 'role' => 'player']);
    }

    public function test_get_or_assign_returns_existing_membership(): void
    {
        $player = $this->makePlayer();
        $guild  = $this->makeGuild();
        $this->service->joinGuild($player, $guild, 'moderator');

        $member = $this->service->getOrAssign($player, $guild);

        $this->assertEquals('moderator', $member->role);
        $this->assertDatabaseCount('guild_player', 1);
    }

    public function test_get_or_assign_promotes_to_admin_when_player_is_owner(): void
    {
        $player = $this->makePlayer(['discord_id' => '777888999']);
        $guild  = $this->makeGuild(['owner_discord_id' => '777888999']);
        $this->service->joinGuild($player, $guild, 'player');

        $member = $this->service->getOrAssign($player, $guild);

        $this->assertEquals('admin', $member->role);
    }
}
