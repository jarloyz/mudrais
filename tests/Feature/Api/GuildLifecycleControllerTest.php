<?php

namespace Tests\Feature\Api;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildLifecycleControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $botSecret = 'test_bot_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.discord.bot_token' => $this->botSecret]);
    }

    private function registerGuild(array $data, ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/guilds/register', $data, [
            'X-Bot-Secret' => $secret ?? $this->botSecret,
        ]);
    }

    public function test_request_without_secret_returns_403(): void
    {
        $response = $this->postJson('/api/guilds/register', [
            'discord_guild_id'  => 'guild_001',
            'owner_discord_id'  => 'owner_001',
        ]);

        $response->assertStatus(403);
    }

    public function test_request_with_wrong_secret_returns_403(): void
    {
        $response = $this->registerGuild([
            'discord_guild_id' => 'guild_001',
            'owner_discord_id' => 'owner_001',
        ], 'wrong_secret');

        $response->assertStatus(403);
    }

    public function test_valid_payload_creates_guild(): void
    {
        $response = $this->registerGuild([
            'discord_guild_id' => 'new_guild_001',
            'owner_discord_id' => 'owner_discord_001',
        ]);

        $response->assertOk()->assertJsonStructure(['status', 'guild_id']);
        $this->assertEquals('ok', $response->json('status'));
        $this->assertDatabaseHas('guilds', [
            'discord_guild_id' => 'new_guild_001',
            'owner_discord_id' => 'owner_discord_001',
        ]);
    }

    public function test_valid_payload_updates_existing_guild(): void
    {
        $archetype = Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto']
        );
        $existing = Guild::create(['discord_guild_id' => 'existing_guild', 'is_active' => true]);
        $existing->archetypes()->attach($archetype->id, ['is_primary' => true]);

        $response = $this->registerGuild([
            'discord_guild_id' => 'existing_guild',
            'owner_discord_id' => 'new_owner_id',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('guilds', 1);
        $this->assertDatabaseHas('guilds', ['owner_discord_id' => 'new_owner_id']);
    }

    public function test_owner_with_existing_player_receives_admin_role(): void
    {
        Player::create([
            'discord_id' => 'owner_discord_002',
            'username'   => 'guild_owner',
            'energy'     => 100,
            'coin'       => 0,
            'elo'        => 1000,
            'is_active'  => true,
        ]);

        $response = $this->registerGuild([
            'discord_guild_id' => 'guild_owner_test',
            'owner_discord_id' => 'owner_discord_002',
        ]);

        $response->assertOk();

        $guild = Guild::where('discord_guild_id', 'guild_owner_test')->first();
        $this->assertDatabaseHas('guild_members', [
            'guild_id' => $guild->id,
            'role'     => 'admin',
        ]);
    }

    public function test_owner_without_player_does_not_create_membership(): void
    {
        $response = $this->registerGuild([
            'discord_guild_id' => 'guild_no_player',
            'owner_discord_id' => 'nonexistent_player',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('guild_members', 0);
    }

    public function test_missing_discord_guild_id_returns_422(): void
    {
        $response = $this->registerGuild(['owner_discord_id' => 'owner_001']);

        $response->assertStatus(422)->assertJsonValidationErrors(['discord_guild_id']);
    }

    public function test_missing_owner_discord_id_returns_422(): void
    {
        $response = $this->registerGuild(['discord_guild_id' => 'guild_001']);

        $response->assertStatus(422)->assertJsonValidationErrors(['owner_discord_id']);
    }
}
