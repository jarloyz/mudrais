<?php

namespace Tests\Unit\Middleware;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\Player;
use App\Http\Middleware\EnsurePlayerHasEnergy;
use App\Domains\Matchmaking\Models\Archetype;
use App\Services\Discord\CommandEnergyCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsurePlayerHasEnergyTest extends TestCase
{
    use RefreshDatabase;

    private EnsurePlayerHasEnergy $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new EnsurePlayerHasEnergy(app(CommandEnergyCostService::class));
    }

    private function makePlayer(int $energy = 100): Player
    {
        return Player::create([
            'discord_id' => (string) fake()->unique()->numerify('##########'),
            'username'   => fake()->userName(),
            'energy'     => $energy,
            'coin'       => 0,
            'elo'        => 1000,
            'is_active'  => true,
        ]);
    }

    private function makeGuild(): Guild
    {
        $archetype = Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto']
        );
        return Guild::create([
            'discord_guild_id' => 'guild_' . uniqid(),
            'archetype_id'     => $archetype->id,
            'is_active'        => true,
        ]);
    }

    private function makeRequest(array $payload, ?Player $player = null, ?Guild $guild = null): Request
    {
        $request = Request::create('/api/discord/interactions', 'POST', $payload);
        if ($player) {
            $request->attributes->set('discord_player', $player);
        }
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

    public function test_passes_when_no_player_attached(): void
    {
        $guild    = $this->makeGuild();
        $request  = $this->makeRequest(['type' => 2, 'data' => ['name' => 'buscar-partner']], null, $guild);
        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }

    public function test_passes_when_command_has_zero_cost(): void
    {
        $player   = $this->makePlayer(0);
        $guild    = $this->makeGuild();
        $request  = $this->makeRequest(['type' => 2, 'data' => ['name' => 'status']], $player, $guild);
        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }

    public function test_returns_ephemeral_when_energy_insufficient(): void
    {
        $player  = $this->makePlayer(2);
        $guild   = $this->makeGuild();
        $request = $this->makeRequest(['type' => 2, 'data' => ['name' => 'buscar-partner']], $player, $guild);

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));
        $body     = json_decode($response->getContent(), true);

        $this->assertEquals(4, $body['type']);
        $this->assertEquals(64, $body['data']['flags']);
        $this->assertStringContainsString('energía', $body['data']['content']);
        $this->assertStringContainsString('2', $body['data']['content']);
    }

    public function test_passes_when_energy_is_exactly_enough(): void
    {
        $cost    = config('historia.discord_command_energy.buscar-partner', 5);
        $player  = $this->makePlayer($cost);
        $guild   = $this->makeGuild();
        $request = $this->makeRequest(['type' => 2, 'data' => ['name' => 'buscar-partner']], $player, $guild);

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }

    public function test_passes_when_energy_is_more_than_enough(): void
    {
        $player  = $this->makePlayer(100);
        $guild   = $this->makeGuild();
        $request = $this->makeRequest(['type' => 2, 'data' => ['name' => 'buscar-partner']], $player, $guild);

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['passed' => true]));

        $this->assertTrue(json_decode($response->getContent(), true)['passed']);
    }
}
