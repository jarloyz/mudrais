<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\Vault;
use App\Models\Avatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SceneStateTest extends TestCase
{
    use RefreshDatabase;

    private function createVaultAndScene(string $status = 'ready'): Activity
    {
        Vault::query()->create(['id' => 'vault_t', 'name' => 'Vault T', 'status' => 'active']);

        return Activity::query()->create([
            'id' => 'scene_t',
            'vault_id' => 'vault_t',
            'title' => 'Escena T',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => $status,
            'round_number' => 2,
        ]);
    }

    public function test_returns_scene_state_without_characters(): void
    {
        $this->createVaultAndScene('ready');

        $this->getJson('/api/v2/activity/state?scene_id=scene_t')
            ->assertOk()
            ->assertJsonPath('sceneId', 'scene_t')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('roundNumber', 2)
            ->assertJsonPath('currentTurnCharacterId', null)
            ->assertJsonCount(0, 'characters');
    }

    public function test_returns_characters_with_vtt_fields(): void
    {
        $scene = $this->createVaultAndScene('in_progress');

        $playerId = \App\Models\Player::factory()->create()->id;

        Avatar::query()->create([
            'id' => 'char_kira',
            'vault_id' => 'vault_t',
            'name' => 'Kira',
        ]);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_t',
            'avatar_id' => 'char_kira',
            'scene_role' => 'player',
            'controlled_by_player_id' => $playerId,
            'initiative_score' => 15,
            'has_acted_this_round' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scene->update(['current_turn_character_id' => 'char_kira']);

        $this->getJson('/api/v2/activity/state?scene_id=scene_t')
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('currentTurnCharacterId', 'char_kira')
            ->assertJsonCount(1, 'characters')
            ->assertJsonPath('characters.0.id', 'char_kira')
            ->assertJsonPath('characters.0.name', 'Kira')
            ->assertJsonPath('characters.0.scene_role', 'player')
            ->assertJsonPath('characters.0.controlled_by_player_id', $playerId)
            ->assertJsonPath('characters.0.initiative_score', 15)
            ->assertJsonPath('characters.0.has_acted_this_round', false);
    }

    public function test_returns_404_for_nonexistent_scene(): void
    {
        $this->getJson('/api/v2/activity/state?scene_id=no_existe')
            ->assertNotFound()
            ->assertJsonPath('error', 'Escena no encontrada.');
    }

    public function test_requires_scene_id_parameter(): void
    {
        $this->getJson('/api/v2/activity/state')
            ->assertUnprocessable();
    }

    public function test_returns_draft_status_for_unstarted_scene(): void
    {
        $this->createVaultAndScene('draft');

        $this->getJson('/api/v2/activity/state?scene_id=scene_t')
            ->assertOk()
            ->assertJsonPath('status', 'draft');
    }

    public function test_characters_ordered_by_initiative_descending(): void
    {
        $this->createVaultAndScene('in_progress');

        foreach ([['char_a', 'Alfa', 5], ['char_b', 'Beta', 20], ['char_c', 'Gamma', 10]] as [$id, $name, $score]) {
            Avatar::query()->create(['id' => $id, 'vault_id' => 'vault_t', 'name' => $name]);
            DB::table('activity_members')->insert([
                'activity_id' => 'scene_t',
                'avatar_id' => $id,
                'scene_role' => 'npc',
                'initiative_score' => $score,
                'has_acted_this_round' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/v2/activity/state?scene_id=scene_t')->assertOk();
        $characters = $response->json('characters');

        $this->assertSame('char_b', $characters[0]['id']); // 20 primero
        $this->assertSame('char_c', $characters[1]['id']); // 10 segundo
        $this->assertSame('char_a', $characters[2]['id']); // 5 último
    }
}
