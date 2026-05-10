<?php

namespace Tests\Unit\Services;

use App\Application\Services\GuildValidationService;
use App\Domains\Matchmaking\Models\Archetype;
use App\Models\Guild;
use App\Models\GuildProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private GuildValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GuildValidationService();
        $this->seedArchetype();
    }

    private function seedArchetype(): Archetype
    {
        return Archetype::create([
            'name'               => 'TTRPG Texto',
            'qdrant_vector_name' => 'ttrpg_text_v1',
        ]);
    }

    public function test_find_or_register_creates_new_guild(): void
    {
        $guild = $this->service->findOrRegister('discord_guild_001');

        $this->assertInstanceOf(Guild::class, $guild);
        $this->assertEquals('discord_guild_001', $guild->discord_guild_id);
        $this->assertTrue($guild->is_active);
        $this->assertEquals(1, $guild->plan_tier);
        $this->assertEquals(50, $guild->profile_quota);
        $this->assertDatabaseHas('guilds', ['discord_guild_id' => 'discord_guild_001']);
        $this->assertDatabaseHas('archetype_guild', ['guild_id' => $guild->id]);
    }

    public function test_find_or_register_returns_existing_guild(): void
    {
        $archetype = Archetype::first();
        $existing  = Guild::create([
            'discord_guild_id' => 'existing_guild',
            'is_active'        => false,
        ]);
        $existing->archetypes()->attach($archetype->id, ['is_primary' => true]);

        $guild = $this->service->findOrRegister('existing_guild');

        $this->assertEquals($existing->id, $guild->id);
        $this->assertFalse($guild->is_active);
        $this->assertDatabaseCount('guilds', 1);
    }

    public function test_assert_active_returns_true_for_active_guild(): void
    {
        $guild = Guild::create([
            'discord_guild_id' => 'active_guild',
            'is_active'        => true,
        ]);

        $this->assertTrue($this->service->assertActive($guild));
    }

    public function test_assert_active_returns_false_for_inactive_guild(): void
    {
        $guild = Guild::create([
            'discord_guild_id' => 'inactive_guild',
            'is_active'        => false,
        ]);

        $this->assertFalse($this->service->assertActive($guild));
    }

    public function test_assert_within_quota_returns_true_when_below_limit(): void
    {
        $guild = Guild::create([
            'discord_guild_id' => 'quota_guild',
            'profile_quota'    => 5,
        ]);

        $this->assertTrue($this->service->assertWithinQuota($guild));
    }

    public function test_assert_within_quota_returns_false_when_limit_reached(): void
    {
        $guild = Guild::create([
            'discord_guild_id' => 'full_guild',
            'profile_quota'    => 2,
        ]);

        GuildProfile::create(['guild_id' => $guild->id, 'discord_user_id' => 'user1', 'status' => 'active']);
        GuildProfile::create(['guild_id' => $guild->id, 'discord_user_id' => 'user2', 'status' => 'active']);

        $this->assertFalse($this->service->assertWithinQuota($guild));
    }

    public function test_ensure_guild_profile_creates_membership(): void
    {
        $guild = Guild::create([
            'discord_guild_id' => 'member_guild',
        ]);

        $profile = $this->service->ensureGuildProfile($guild, 'discord_user_123');

        $this->assertInstanceOf(GuildProfile::class, $profile);
        $this->assertEquals('active', $profile->status);
        $this->assertDatabaseHas('guild_profiles', [
            'guild_id'        => $guild->id,
            'discord_user_id' => 'discord_user_123',
        ]);
    }

    public function test_ensure_guild_profile_is_idempotent(): void
    {
        $guild = Guild::create([
            'discord_guild_id' => 'idempotent_guild',
        ]);

        $this->service->ensureGuildProfile($guild, 'user_x');
        $this->service->ensureGuildProfile($guild, 'user_x');

        $this->assertDatabaseCount('guild_profiles', 1);
    }
}
