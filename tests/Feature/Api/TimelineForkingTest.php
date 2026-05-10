<?php

namespace Tests\Feature\Api;

use App\Models\Avatar;
use App\Models\Continuity;
use App\Models\ContinuityCommit;
use App\Models\ContinuityCommitSceneState;
use App\Models\ContinuitySceneState;
use App\Models\Activity;
use App\Models\SceneActiveContinuity;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimelineForkingTest extends TestCase
{
    use RefreshDatabase;

    private function seedBaseScene(): Activity
    {
        Vault::query()->create(['id' => 'vault_f', 'name' => 'Vault Fork', 'status' => 'active']);

        return Activity::query()->create([
            'id' => 'scene_src',
            'vault_id' => 'vault_f',
            'title' => 'Escena Fuente',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => 'ready',
            'draft' => 'El héroe entra.',
            'round_number' => 3,
        ]);
    }

    private function seedContinuityWithCommit(string $sceneId = 'scene_src'): ContinuityCommit
    {
        Continuity::query()->create([
            'id' => 'cont_src',
            'root_id' => 'cont_src',
            'label' => 'Cont Fuente',
            'status' => 'active',
        ]);

        SceneActiveContinuity::query()->create([
            'activity_id' => $sceneId,
            'continuity_id' => 'cont_src',
        ]);

        ContinuitySceneState::query()->create([
            'continuity_id' => 'cont_src',
            'activity_id' => $sceneId,
            'draft' => 'El héroe entra.',
        ]);

        $commit = ContinuityCommit::query()->create([
            'continuity_id' => 'cont_src',
            'activity_id' => $sceneId,
            'parent_commit_id' => null,
            'source_parent_commit_id' => null,
            'turn_index' => 2,
            'mode' => 'write_scene',
            'message' => 'Turno inicial',
        ]);

        ContinuityCommitSceneState::query()->create([
            'commit_id' => $commit->id,
            'continuity_id' => 'cont_src',
            'activity_id' => $sceneId,
            'draft' => 'El héroe entra.',
        ]);

        SceneActiveContinuity::query()
            ->where('activity_id', $sceneId)
            ->update(['continuity_commit_id' => $commit->id]);

        return $commit;
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    public function test_fork_creates_new_scene_as_draft(): void
    {
        $this->seedBaseScene();

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork',
        ])->assertCreated()
            ->assertJsonPath('sourceSceneId', 'scene_src')
            ->assertJsonPath('forkedSceneId', 'scene_fork')
            ->assertJsonPath('status', 'draft');

        $this->assertDatabaseHas('activities', [
            'id' => 'scene_fork',
            'vault_id' => 'vault_f',
            'status' => 'draft',
            'title' => 'Escena Fuente [fork]',
            'round_number' => 1,
            'current_turn_character_id' => null,
        ]);
    }

    public function test_fork_preserves_source_scene_unchanged(): void
    {
        $this->seedBaseScene();

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork',
        ])->assertCreated();

        // La escena fuente no debe modificarse
        $this->assertDatabaseHas('activities', [
            'id' => 'scene_src',
            'status' => 'ready',
            'round_number' => 3,
        ]);
    }

    public function test_fork_with_continuity_branches_from_head_commit(): void
    {
        $this->seedBaseScene();
        $commit = $this->seedContinuityWithCommit();

        $response = $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork2',
            'new_continuity_id' => 'cont_fork',
        ])->assertCreated();

        $response->assertJsonPath('continuityId', 'cont_fork')
            ->assertJsonPath('parentContinuityId', 'cont_src')
            ->assertJsonPath('sourceCommitId', $commit->id);

        // La continuidad forkeada existe con el parent correcto
        $this->assertDatabaseHas('continuities', [
            'id' => 'cont_fork',
            'parent_id' => 'cont_src',
        ]);

        // La escena forkeada tiene su propia SceneActiveContinuity
        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'scene_fork2',
            'continuity_id' => 'cont_fork',
        ]);

        // La escena fuente conserva su continuidad original
        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'scene_src',
            'continuity_id' => 'cont_src',
        ]);
    }

    public function test_fork_creates_scene_state_and_commit_for_new_scene(): void
    {
        $this->seedBaseScene();
        $this->seedContinuityWithCommit();

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork3',
            'new_continuity_id' => 'cont_fork3',
        ])->assertCreated();

        // El estado de escena apunta al nuevo scene_id
        $this->assertDatabaseHas('continuity_scene_states', [
            'continuity_id' => 'cont_fork3',
            'activity_id' => 'scene_fork3',
            'draft' => 'El héroe entra.',
        ]);

        // El commit inicial del fork también apunta al nuevo scene_id
        $this->assertDatabaseHas('continuity_commits', [
            'continuity_id' => 'cont_fork3',
            'activity_id' => 'scene_fork3',
        ]);

        // No hay registros del fork contaminando el scene_id fuente
        $this->assertDatabaseMissing('continuity_scene_states', [
            'continuity_id' => 'cont_fork3',
            'activity_id' => 'scene_src',
        ]);
    }

    public function test_fork_clones_characters_with_their_controls(): void
    {
        $this->seedBaseScene();
        $playerId = \App\Models\Player::factory()->create()->id;

        Avatar::query()->create(['id' => 'char_x', 'vault_id' => 'vault_f', 'name' => 'Xena']);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_src',
            'avatar_id' => 'char_x',
            'scene_role' => 'player',
            'controlled_by_player_id' => $playerId,
            'initiative_score' => 10,
            'has_acted_this_round' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork4',
        ])->assertCreated();

        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_fork4',
            'avatar_id' => 'char_x',
            'scene_role' => 'player',
            'controlled_by_player_id' => $playerId,
            'initiative_score' => 10,
            'has_acted_this_round' => false, // reseteado
        ]);
    }

    public function test_fork_allows_reassigning_character_controls(): void
    {
        $this->seedBaseScene();
        $player1 = \App\Models\Player::factory()->create();
        $player2 = \App\Models\Player::factory()->create();

        Avatar::query()->create(['id' => 'char_y', 'vault_id' => 'vault_f', 'name' => 'Yuna']);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_src',
            'avatar_id' => 'char_y',
            'scene_role' => 'player',
            'controlled_by_player_id' => $player1->id,
            'initiative_score' => 5,
            'has_acted_this_round' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork5',
            'reassign_controls' => ['char_y' => $player2->id],
        ])->assertCreated();

        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_fork5',
            'avatar_id' => 'char_y',
            'controlled_by_player_id' => $player2->id,
        ]);
    }

    public function test_fork_with_custom_title(): void
    {
        $this->seedBaseScene();

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork6',
            'title' => 'Línea Alternativa Alpha',
        ])->assertCreated();

        $this->assertDatabaseHas('activities', [
            'id' => 'scene_fork6',
            'title' => 'Línea Alternativa Alpha',
        ]);
    }

    public function test_fork_returns_422_for_nonexistent_source_scene(): void
    {
        $this->postJson('/api/v2/activities/no_existe/fork', [
            'new_scene_id' => 'fork_x',
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'Escena fuente no encontrada: no_existe');
    }

    public function test_fork_returns_422_when_new_scene_id_already_exists(): void
    {
        $this->seedBaseScene();

        // Crear la escena de destino antes del fork
        Activity::query()->create([
            'id' => 'scene_dup',
            'vault_id' => 'vault_f',
            'title' => 'Duplicada',
            'chapter' => 1,
            'scene_number' => 2,
            'status' => 'draft',
            'draft' => '',
        ]);

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_dup',
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'Ya existe una escena con id: scene_dup');
    }

    public function test_fork_requires_new_scene_id(): void
    {
        $this->postJson('/api/v2/activities/scene_src/fork', [])
            ->assertUnprocessable();
    }

    public function test_fork_without_continuity_creates_standalone_continuity(): void
    {
        $this->seedBaseScene();
        // Sin SceneActiveContinuity ni Continuity en el source

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork7',
            'new_continuity_id' => 'cont_fresh',
        ])->assertCreated()
            ->assertJsonPath('parentContinuityId', null)
            ->assertJsonPath('sourceCommitId', null);

        $this->assertDatabaseHas('continuities', [
            'id' => 'cont_fresh',
            'parent_id' => null,
        ]);

        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'scene_fork7',
            'continuity_id' => 'cont_fresh',
        ]);
    }

    // ─── Tests de la tarea 53 ────────────────────────────────────────────────

    public function test_fork_assigns_admin_role_to_forking_user(): void
    {
        $this->seedBaseScene();
        $admin = \App\Models\Player::factory()->create();

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork_admin',
            'user_id' => $admin->id,
        ])->assertCreated()
            ->assertJsonPath('forkingPlayerId', $admin->id)
            ->assertJsonPath('adminAssigned', true);

        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_fork_admin',
            'controlled_by_player_id' => $admin->id,
            'role' => 'admin',
        ]);
    }

    public function test_fork_converts_other_users_characters_to_npcs(): void
    {
        $this->seedBaseScene();
        $forkingPlayer = \App\Models\Player::factory()->create();
        $companion = \App\Models\Player::factory()->create();

        Avatar::query()->create(['id' => 'char_fork', 'vault_id' => 'vault_f', 'name' => 'Forker']);
        Avatar::query()->create(['id' => 'char_comp', 'vault_id' => 'vault_f', 'name' => 'Companion']);

        DB::table('activity_members')->insert([
            ['activity_id' => 'scene_src', 'avatar_id' => 'char_fork',
             'scene_role' => 'player', 'controlled_by_player_id' => $forkingPlayer->id,
             'initiative_score' => 10, 'has_acted_this_round' => false,
             'created_at' => now(), 'updated_at' => now()],
            ['activity_id' => 'scene_src', 'avatar_id' => 'char_comp',
             'scene_role' => 'player', 'controlled_by_player_id' => $companion->id,
             'initiative_score' => 8, 'has_acted_this_round' => false,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork_npcs',
            'user_id' => $forkingPlayer->id,
        ])->assertCreated();

        // El personaje del forking user conserva su control
        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_fork_npcs',
            'avatar_id' => 'char_fork',
            'controlled_by_player_id' => $forkingPlayer->id,
        ]);

        // El personaje del compañero se convierte en NPC
        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_fork_npcs',
            'avatar_id' => 'char_comp',
            'controlled_by_player_id' => null,
        ]);
    }

    public function test_fork_explicit_reassign_overrides_ex_companion_policy(): void
    {
        $this->seedBaseScene();
        $forkingPlayer = \App\Models\Player::factory()->create();
        $companion = \App\Models\Player::factory()->create();
        $newPlayer = \App\Models\Player::factory()->create();

        Avatar::query()->create(['id' => 'char_c1', 'vault_id' => 'vault_f', 'name' => 'Comp1']);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_src', 'avatar_id' => 'char_c1',
            'scene_role' => 'player', 'controlled_by_player_id' => $companion->id,
            'initiative_score' => 5, 'has_acted_this_round' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // reassign_controls tiene prioridad: entregar char_c1 a newPlayer en lugar de NPC
        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork_remap',
            'user_id' => $forkingPlayer->id,
            'reassign_controls' => ['char_c1' => $newPlayer->id],
        ])->assertCreated();

        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_fork_remap',
            'avatar_id' => 'char_c1',
            'controlled_by_player_id' => $newPlayer->id,
        ]);
    }

    public function test_fork_without_user_id_does_not_create_scene_users_entry(): void
    {
        $this->seedBaseScene();

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork_anon',
        ])->assertCreated()
            ->assertJsonPath('adminAssigned', false)
            ->assertJsonPath('forkingPlayerId', null);

        $this->assertDatabaseMissing('activity_members', [
            'activity_id' => 'scene_fork_anon',
        ]);
    }

    public function test_fork_preserves_npc_characters_as_npc(): void
    {
        $this->seedBaseScene();
        $forkingPlayer = \App\Models\Player::factory()->create();

        Avatar::query()->create(['id' => 'char_npc1', 'vault_id' => 'vault_f', 'name' => 'Guard']);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_src', 'avatar_id' => 'char_npc1',
            'scene_role' => 'npc', 'controlled_by_player_id' => null,
            'initiative_score' => 3, 'has_acted_this_round' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v2/activities/scene_src/fork', [
            'new_scene_id' => 'scene_fork_npc',
            'user_id' => $forkingPlayer->id,
        ])->assertCreated();

        // NPCs (controlled_by_player_id = null) no se modifican
        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_fork_npc',
            'avatar_id' => 'char_npc1',
            'controlled_by_player_id' => null,
            'scene_role' => 'npc',
        ]);
    }
}
