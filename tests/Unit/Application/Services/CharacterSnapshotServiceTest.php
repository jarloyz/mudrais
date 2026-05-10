<?php

namespace Tests\Unit\Application\Services;

use App\Application\Services\CharacterSnapshotService;
use App\Models\Avatar;
use App\Models\CharacterInstance;
use App\Models\CharacterInventory;
use App\Models\Activity;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class CharacterSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private CharacterSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CharacterSnapshotService(new ArrayStructuredLogger());
    }

    private function createVaultAndCharacter(string $charId = 'char_snap'): Avatar
    {
        Vault::query()->firstOrCreate(
            ['id' => 'vault_snap'],
            ['name' => 'Vault Snap', 'status' => 'active']
        );

        return Avatar::query()->create([
            'id' => $charId,
            'name' => 'Snapora',
            'vault_id' => 'vault_snap',
        ]);
    }

    private function createScene(string $sceneId = 'scene_snap'): Activity
    {
        return Activity::query()->create([
            'id' => $sceneId,
            'vault_id' => 'vault_snap',
            'title' => 'Escena Snap',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => \App\Domains\Matchmaking\Enums\ActivityStatus::RECRUITING->value,
            'draft' => 'Inicio',
        ]);
    }

    public function test_snapshot_creates_character_instance_record(): void
    {
        $this->createVaultAndCharacter();
        $this->createScene();

        $result = $this->service->snapshot('scene_snap', 'char_snap');

        $this->assertTrue($result['created']);
        $this->assertInstanceOf(CharacterInstance::class, $result['instance']);
        $this->assertSame(1, $result['instance']->version);

        $this->assertDatabaseHas('character_instances', [
            'activity_id' => 'scene_snap',
            'avatar_id' => 'char_snap',
            'version' => 1,
        ]);
    }

    public function test_snapshot_data_includes_character_base_fields(): void
    {
        $this->createVaultAndCharacter();
        $this->createScene();

        $result = $this->service->snapshot('scene_snap', 'char_snap');

        $data = $result['instance']->snapshot_data;
        $this->assertSame('char_snap', $data['character_id']);
        $this->assertSame('Snapora', $data['name']);
        $this->assertSame('vault_snap', $data['vault_id']);
        $this->assertArrayHasKey('inventory', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('snapshot_version_note', $data);
    }

    public function test_snapshot_captures_inventory_items(): void
    {
        $this->createVaultAndCharacter();
        $this->createScene();

        CharacterInventory::query()->create([
            'character_id' => 'char_snap',
            'item_name' => 'Pistola Glock',
            'category' => 'weapon',
            'quantity' => 1,
            'is_quick_slot' => true,
        ]);

        CharacterInventory::query()->create([
            'character_id' => 'char_snap',
            'item_name' => 'Botiquín',
            'category' => 'medicine',
            'quantity' => 3,
            'is_quick_slot' => false,
        ]);

        $result = $this->service->snapshot('scene_snap', 'char_snap');

        $inventory = $result['instance']->inventory();
        $this->assertCount(2, $inventory);

        $names = array_column($inventory, 'item_name');
        $this->assertContains('Pistola Glock', $names);
        $this->assertContains('Botiquín', $names);

        $pistola = collect($inventory)->firstWhere('item_name', 'Pistola Glock');
        $this->assertTrue($pistola['is_quick_slot']);
        $this->assertSame(1, $pistola['quantity']);
    }

    public function test_snapshot_updates_version_on_re_snapshot(): void
    {
        $this->createVaultAndCharacter();
        $this->createScene();

        $first = $this->service->snapshot('scene_snap', 'char_snap');
        $this->assertTrue($first['created']);
        $this->assertSame(1, $first['instance']->version);

        // Segunda captura (re-snapshot — personaje fue modificado en el Baúl)
        $second = $this->service->snapshot('scene_snap', 'char_snap');
        $this->assertFalse($second['created']);
        $this->assertSame(2, $second['instance']->version);

        // Solo debe existir un registro
        $this->assertSame(1, CharacterInstance::query()
            ->where('activity_id', 'scene_snap')
            ->where('avatar_id', 'char_snap')
            ->count());
    }

    public function test_snapshot_all_creates_instances_for_every_scene_character(): void
    {
        Vault::query()->firstOrCreate(
            ['id' => 'vault_snap'],
            ['name' => 'Vault Snap', 'status' => 'active']
        );
        $this->createScene();

        foreach (['char_a', 'char_b', 'char_c'] as $id) {
            Avatar::query()->create(['id' => $id, 'name' => ucfirst($id), 'vault_id' => 'vault_snap']);
            \Illuminate\Support\Facades\DB::table('activity_members')->insert([
                'activity_id' => 'scene_snap',
                'avatar_id' => $id,
                'scene_role' => 'npc',
                'initiative_score' => 0,
                'has_acted_this_round' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $results = $this->service->snapshotAll('scene_snap');

        $this->assertCount(3, $results);
        $this->assertSame(3, CharacterInstance::query()->where('activity_id', 'scene_snap')->count());

        foreach ($results as $r) {
            $this->assertTrue($r['created']);
        }
    }

    public function test_get_snapshot_returns_null_before_first_snapshot(): void
    {
        $this->createVaultAndCharacter();
        $this->createScene();

        $this->assertNull($this->service->getSnapshot('scene_snap', 'char_snap'));
    }

    public function test_get_snapshot_returns_instance_after_snapshot(): void
    {
        $this->createVaultAndCharacter();
        $this->createScene();

        $this->service->snapshot('scene_snap', 'char_snap');

        $instance = $this->service->getSnapshot('scene_snap', 'char_snap');
        $this->assertInstanceOf(CharacterInstance::class, $instance);
        $this->assertSame('char_snap', $instance->avatar_id);
    }

    public function test_snapshot_throws_for_nonexistent_scene(): void
    {
        $this->createVaultAndCharacter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Escena no encontrada');

        $this->service->snapshot('scene_no_existe', 'char_snap');
    }

    public function test_snapshot_throws_for_nonexistent_character(): void
    {
        Vault::query()->firstOrCreate(
            ['id' => 'vault_snap'],
            ['name' => 'Vault Snap', 'status' => 'active']
        );
        $this->createScene();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Personaje no encontrado');

        $this->service->snapshot('scene_snap', 'char_no_existe');
    }

    public function test_stats_are_empty_at_snapshot_time(): void
    {
        $this->createVaultAndCharacter();
        $this->createScene();

        $result = $this->service->snapshot('scene_snap', 'char_snap');

        $this->assertSame([], $result['instance']->stats());
    }
}
