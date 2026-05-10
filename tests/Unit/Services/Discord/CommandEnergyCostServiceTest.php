<?php

namespace Tests\Unit\Services\Discord;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildCommandCost;
use App\Domains\Matchmaking\Models\Archetype;
use App\Services\Discord\CommandEnergyCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandEnergyCostServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommandEnergyCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommandEnergyCostService();
    }

    private function makeGuild(): Guild
    {
        $archetype = Archetype::firstOrCreate(
            ['name' => 'Test Archetype'],
            ['qdrant_vector_name' => 'test_v1']
        );
        return Guild::create([
            'discord_guild_id' => 'guild_' . uniqid(),
            'archetype_id'     => $archetype->id,
            'is_active'        => true,
        ]);
    }

    public function test_get_cost_returns_guild_override_when_set(): void
    {
        $guild = $this->makeGuild();
        GuildCommandCost::create([
            'guild_id'     => $guild->id,
            'command_name' => 'buscar-partner',
            'energy_cost'  => 20,
        ]);

        $cost = $this->service->getCost('buscar-partner', $guild);

        $this->assertEquals(20, $cost);
    }

    public function test_get_cost_returns_config_default_when_no_override(): void
    {
        $guild = $this->makeGuild();

        $cost = $this->service->getCost('buscar-partner', $guild);

        $this->assertEquals(config('historia.discord_command_energy.buscar-partner', 0), $cost);
    }

    public function test_get_cost_returns_zero_for_unknown_command(): void
    {
        $guild = $this->makeGuild();

        $cost = $this->service->getCost('comando-desconocido', $guild);

        $this->assertEquals(0, $cost);
    }

    public function test_get_cost_guild_override_of_zero_exempts_from_config_cost(): void
    {
        $guild = $this->makeGuild();
        GuildCommandCost::create([
            'guild_id'     => $guild->id,
            'command_name' => 'buscar-partner',
            'energy_cost'  => 0,
        ]);

        $cost = $this->service->getCost('buscar-partner', $guild);

        $this->assertEquals(0, $cost);
    }

    public function test_set_guild_override_creates_new_override(): void
    {
        $guild = $this->makeGuild();

        $override = $this->service->setGuildOverride($guild, 'ficha', 10);

        $this->assertEquals(10, $override->energy_cost);
        $this->assertDatabaseHas('guild_command_costs', [
            'guild_id'     => $guild->id,
            'command_name' => 'ficha',
            'energy_cost'  => 10,
        ]);
    }

    public function test_set_guild_override_updates_existing_override(): void
    {
        $guild = $this->makeGuild();
        $this->service->setGuildOverride($guild, 'ficha', 5);
        $this->service->setGuildOverride($guild, 'ficha', 15);

        $this->assertDatabaseCount('guild_command_costs', 1);
        $this->assertDatabaseHas('guild_command_costs', ['energy_cost' => 15]);
    }

    public function test_set_guild_override_throws_for_negative_cost(): void
    {
        $guild = $this->makeGuild();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setGuildOverride($guild, 'ficha', -1);
    }

    public function test_remove_guild_override_deletes_the_record(): void
    {
        $guild = $this->makeGuild();
        $this->service->setGuildOverride($guild, 'status', 3);

        $this->service->removeGuildOverride($guild, 'status');

        $this->assertDatabaseMissing('guild_command_costs', [
            'guild_id'     => $guild->id,
            'command_name' => 'status',
        ]);
    }

    public function test_remove_guild_override_is_safe_when_no_override_exists(): void
    {
        $guild = $this->makeGuild();

        $this->service->removeGuildOverride($guild, 'status');

        $this->assertDatabaseCount('guild_command_costs', 0);
    }
}
