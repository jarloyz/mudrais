<?php

namespace Tests\Unit\Middleware;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\Player;
use App\Http\Middleware\EnsureDiscordCommandPermission;
use App\Domains\Matchmaking\Models\Archetype;
use App\Services\Auth\GuildMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureDiscordCommandPermissionTest extends TestCase
{
    use RefreshDatabase;

    private EnsureDiscordCommandPermission $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new EnsureDiscordCommandPermission(app(GuildMembershipService::class));
    }

    private function makeArchetype(): Archetype
    {
        return Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto']
        );
    }

    private function makePlayer(string $discordId = '123456789'): Player
    {
        return Player::create([
            'discord_id' => $discordId,
            'username'   => 'testplayer',
            'energy'     => 100,
            'coin'       => 0,
            'elo'        => 1000,
            'is_active'  => true,
        ]);
    }

    private function makeGuild(string $discordGuildId = 'guild_001'): Guild
    {
        return Guild::create([
            'discord_guild_id' => $discordGuildId,
            'archetype_id'     => $this->makeArchetype()->id,
            'is_active'        => true,
        ]);
    }

    private function makeRequest(array $payload, ?Guild $guild = null): Request
    {
        $request = Request::create('/api/discord/interactions', 'POST', $payload);
        if ($guild) {
            $request->attributes->set('guild', $guild);
        }
        return $request;
    }

    public function test_ping_always_passes(): void
    {
        $request  = $this->makeRequest(['type' => 1]);
        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }

    public function test_component_interaction_passes_without_role_check(): void
    {
        $request  = $this->makeRequest(['type' => 3, 'guild_id' => 'guild_001']);
        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }

    public function test_modal_submit_passes_without_role_check(): void
    {
        $request  = $this->makeRequest(['type' => 5, 'guild_id' => 'guild_001']);
        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }

    public function test_unregistered_player_with_public_command_passes(): void
    {
        $guild   = $this->makeGuild();
        $request = $this->makeRequest([
            'type'     => 2,
            'guild_id' => $guild->discord_guild_id,
            'data'     => ['name' => 'registro'],
            'member'   => ['user' => ['id' => 'nonexistent_999']],
        ], $guild);

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }

    public function test_unregistered_player_with_non_public_command_returns_ephemeral(): void
    {
        $guild   = $this->makeGuild();
        $request = $this->makeRequest([
            'type'     => 2,
            'guild_id' => $guild->discord_guild_id,
            'data'     => ['name' => 'status'],
            'member'   => ['user' => ['id' => 'nonexistent_999']],
        ], $guild);

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));
        $body     = json_decode($response->getContent(), true);

        $this->assertEquals(4, $body['type']);
        $this->assertEquals(64, $body['data']['flags']);
    }

    public function test_player_with_insufficient_role_returns_ephemeral(): void
    {
        $player = $this->makePlayer('discord_player_001');
        $guild  = $this->makeGuild('guild_002');
        app(GuildMembershipService::class)->joinGuild($player, $guild, 'player');

        $request = $this->makeRequest([
            'type'     => 2,
            'guild_id' => 'guild_002',
            'data'     => ['name' => 'setup'],
            'member'   => ['user' => ['id' => 'discord_player_001']],
        ], $guild);

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));
        $body     = json_decode($response->getContent(), true);

        $this->assertEquals(4, $body['type']);
        $this->assertEquals(64, $body['data']['flags']);
    }

    public function test_player_with_correct_role_passes_and_attaches_to_request(): void
    {
        $player = $this->makePlayer('discord_admin_001');
        $guild  = $this->makeGuild('guild_003');
        app(GuildMembershipService::class)->joinGuild($player, $guild, 'admin');

        $request = $this->makeRequest([
            'type'     => 2,
            'guild_id' => 'guild_003',
            'data'     => ['name' => 'setup'],
            'member'   => ['user' => ['id' => 'discord_admin_001']],
        ], $guild);

        $capturedPlayer = null;
        $this->middleware->handle($request, function ($req) use (&$capturedPlayer) {
            $capturedPlayer = $req->attributes->get('discord_player');
            return response()->json(['passed' => true]);
        });

        $this->assertNotNull($capturedPlayer);
        $this->assertEquals($player->id, $capturedPlayer->id);
    }

    public function test_dm_interaction_passes_without_guild_restriction(): void
    {
        $request = $this->makeRequest([
            'type' => 2,
            'data' => ['name' => 'status'],
            'user' => ['id' => 'dm_user_123'],
        ]);

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }
}
