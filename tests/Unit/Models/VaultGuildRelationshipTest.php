<?php

namespace Tests\Unit\Models;

use App\Domains\Community\Models\Guild;
use App\Domains\Narrative\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaultGuildRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_vault_can_belong_to_a_guild(): void
    {
        $guild = Guild::create(['discord_guild_id' => 'test_guild_001']);
        $vault = Vault::create([
            'id'       => 'vault_test_001',
            'name'     => 'Vault de Prueba',
            'guild_id' => $guild->id,
        ]);

        $this->assertEquals($guild->id, $vault->guild_id);
        $this->assertEquals($guild->id, $vault->guild->id);
    }

    public function test_vault_guild_returns_null_when_no_guild_assigned(): void
    {
        $vault = Vault::create([
            'id'   => 'vault_global_001',
            'name' => 'Vault Global',
        ]);

        $this->assertNull($vault->guild_id);
        $this->assertNull($vault->guild);
    }

    public function test_guild_can_have_multiple_vaults(): void
    {
        $guild = Guild::create(['discord_guild_id' => 'multi_vault_guild']);

        Vault::create(['id' => 'vault_a', 'name' => 'Vault A', 'guild_id' => $guild->id]);
        Vault::create(['id' => 'vault_b', 'name' => 'Vault B', 'guild_id' => $guild->id]);

        $this->assertCount(2, $guild->vaults);
    }

    public function test_vault_guild_id_is_null_after_guild_deleted(): void
    {
        $guild = Guild::create(['discord_guild_id' => 'deletable_guild']);
        $vault = Vault::create([
            'id'       => 'vault_orphan',
            'name'     => 'Vault Orphan',
            'guild_id' => $guild->id,
        ]);

        $guild->delete();
        $vault->refresh();

        $this->assertNull($vault->guild_id);
    }
}
