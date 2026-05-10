<?php

namespace Tests\Unit\Middleware;

use App\Application\Services\GuildValidationService;
use App\Domains\Community\Models\Guild;
use App\Http\Middleware\EnsureDiscordGuildRegistered;
use App\Domains\Matchmaking\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureDiscordGuildRegisteredTest extends TestCase
{
    use RefreshDatabase;

    private function makeArchetype(): Archetype
    {
        return Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto']
        );
    }

    private function runMiddleware(array $payload): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        $service    = app(GuildValidationService::class);
        $middleware = new EnsureDiscordGuildRegistered($service);
        $request    = Request::create('/api/discord/interactions', 'POST', $payload);

        return $middleware->handle($request, fn ($req) => response()->json(['passed' => true]));
    }

    public function test_ping_interaction_always_passes(): void
    {
        $response = $this->runMiddleware(['type' => 1]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['passed'] ?? false);
    }

    public function test_dm_interaction_passes_without_guild_check(): void
    {
        $response = $this->runMiddleware([
            'type' => 2,
            'data' => ['name' => 'status'],
            'user' => ['id' => '123'],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['passed'] ?? false);
    }

    public function test_new_guild_is_auto_registered_and_passes(): void
    {
        $this->makeArchetype();

        $response = $this->runMiddleware([
            'type'     => 2,
            'guild_id' => 'auto_register_guild_001',
            'data'     => ['name' => 'status'],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['passed'] ?? false);
        $this->assertDatabaseHas('guilds', ['discord_guild_id' => 'auto_register_guild_001']);
    }

    public function test_registered_active_guild_attaches_guild_to_request(): void
    {
        $archetype = $this->makeArchetype();
        Guild::create([
            'discord_guild_id' => 'registered_guild_001',
            'archetype_id'     => $archetype->id,
            'is_active'        => true,
        ]);

        $service    = app(GuildValidationService::class);
        $middleware = new EnsureDiscordGuildRegistered($service);
        $request    = Request::create('/api/discord/interactions', 'POST', [
            'type'     => 2,
            'guild_id' => 'registered_guild_001',
            'data'     => ['name' => 'status'],
        ]);

        $capturedGuild = null;
        $middleware->handle($request, function ($req) use (&$capturedGuild) {
            $capturedGuild = $req->attributes->get('guild');
            return response()->json(['passed' => true]);
        });

        $this->assertNotNull($capturedGuild);
        $this->assertEquals('registered_guild_001', $capturedGuild->discord_guild_id);
    }

    public function test_inactive_guild_returns_discord_ephemeral_response(): void
    {
        $archetype = $this->makeArchetype();
        Guild::create([
            'discord_guild_id' => 'inactive_guild_001',
            'archetype_id'     => $archetype->id,
            'is_active'        => false,
        ]);

        $response = $this->runMiddleware([
            'type'     => 2,
            'guild_id' => 'inactive_guild_001',
            'data'     => ['name' => 'status'],
        ]);

        $body = json_decode($response->getContent(), true);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(4, $body['type']);
        $this->assertEquals(64, $body['data']['flags']);
        $this->assertArrayNotHasKey('passed', $body);
    }
}
