<?php

namespace Tests\Feature\Catalog;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_demo_vault_scene_quest_and_character(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('vaults', [
            'id' => 'vault_demo',
            'name' => 'Vault Demo (Laravel)',
        ]);
        $this->assertDatabaseHas('locations', [
            'id' => 'refugio_entrada',
            'vault_id' => 'vault_demo',
        ]);
        $this->assertDatabaseHas('activities', [
            'id' => 'escena_prueba',
            'vault_id' => 'vault_demo',
            'location_id' => 'refugio_entrada',
        ]);
        $this->assertDatabaseHas('quests', [
            'id' => 'fuga_del_refugio',
            'vault_id' => 'vault_demo',
        ]);
        $this->assertDatabaseHas('quest_steps', [
            'quest_id' => 'fuga_del_refugio',
            'stage_number' => 10,
        ]);
        $this->assertDatabaseHas('avatars', [
            'id' => 'lucia_demo',
            'vault_id' => 'vault_demo',
        ]);
        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'escena_prueba',
            'avatar_id' => 'lucia_demo',
            'role' => 'protagonist',
        ]);
        $this->assertDatabaseHas('continuities', [
            'id' => 'cont_demo',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'escena_prueba',
            'continuity_id' => 'cont_demo',
        ]);
        $this->assertDatabaseHas('continuity_quest_statuses', [
            'continuity_id' => 'cont_demo',
            'activity_id' => 'escena_prueba',
            'quest_id' => 'fuga_del_refugio',
            'current_stage_number' => 10,
        ]);
    }
}
