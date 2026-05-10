<?php

namespace Tests\Feature\Api;

use App\Application\UseCases\CheckoutContinuityCommitUseCase;
use App\Application\UseCases\CreateSceneBootstrapUseCase;
use App\Application\UseCases\CreateContinuityBranchUseCase;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\UseCases\GenerateSceneTurnUseCase;
use App\Application\UseCases\RewindContinuityToTurnUseCase;
use App\Models\Avatar;
use App\Models\ContinuityCommit;
use App\Models\ContinuityStateChange;
use App\Models\ContinuityTurn;
use App\Models\Activity;
use App\Models\Vault;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2EndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scene_create_endpoint_returns_generated_scene_payload(): void
    {
        Vault::query()->create([
            'id' => 'vault_demo',
            'name' => 'Vault demo',
            'status' => 'active',
        ]);

        $this->app->instance(GenerateSceneTurnUseCase::class, new class
        {
            public function execute(string $sceneId, string $userMessage, string $mode = 'write_scene', bool $apply = true, ?string $userId = null, ?callable $onChunk = null, ?array $qaLoop = null): array
            {
                return [
                    'sceneId' => $sceneId,
                    'mode' => $mode,
                    'applied' => $apply,
                    'userId' => $userId,
                    'qaLoop' => $qaLoop,
                    'outputMd' => 'Escena generada',
                ];
            }
        });

        $response = $this->postJson('/api/v2/activity/create', [
            'scene_id' => 'scene_demo',
            'vault_id' => 'vault_demo',
            'user_message' => 'continua la escena',
            'user_id' => 9,
            'qa_loop_enabled' => true,
            'qa_loop_max_passes' => 3,
            'qa_loop_min_severity' => 'major',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('sceneId', 'scene_demo')
            ->assertJsonPath('userId', 9)
            ->assertJsonPath('qaLoop.enabled', true)
            ->assertJsonPath('qaLoop.max_passes', 3)
            ->assertJsonPath('qaLoop.min_severity', 'major')
            ->assertJsonPath('scene.id', 'scene_demo')
            ->assertJsonPath('scene.vault_id', 'vault_demo')
            ->assertJsonPath('outputMd', 'Escena generada');

        $this->assertDatabaseHas('activities', [
            'id' => 'scene_demo',
            'vault_id' => 'vault_demo',
            'status' => 'draft',
        ]);
    }

    public function test_scene_bootstrap_endpoint_creates_scene_without_generating_turn(): void
    {
        $this->app->instance(CreateSceneBootstrapUseCase::class, new class
        {
            public function execute(array $payload): array
            {
                return [
                    'sceneCreated' => true,
                    'scene' => [
                        'id' => $payload['scene_id'],
                        'title' => $payload['title'],
                        'vault_id' => $payload['vault_id'],
                        'location_id' => $payload['location_id'],
                        'status' => 'draft',
                    ],
                    'quest' => [
                        'questId' => 'quest_escape',
                        'title' => 'Fuga del refugio',
                        'generated' => true,
                    ],
                    'questStatusSeed' => null,
                ];
            }
        });

        $response = $this->postJson('/api/v2/activity/bootstrap', [
            'scene_id' => 'scene_bootstrap',
            'vault_id' => 'vault_demo',
            'title' => 'Escena bootstrap',
            'location_id' => 'loc_safehouse',
            'quest_prompt' => 'Escapar del refugio.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('sceneCreated', true)
            ->assertJsonPath('scene.id', 'scene_bootstrap')
            ->assertJsonPath('scene.title', 'Escena bootstrap')
            ->assertJsonPath('scene.vault_id', 'vault_demo')
            ->assertJsonPath('scene.location_id', 'loc_safehouse')
            ->assertJsonPath('quest.title', 'Fuga del refugio');
    }

    public function test_scene_create_endpoint_requires_vault_when_scene_does_not_exist(): void
    {
        $response = $this->postJson('/api/v2/activity/create', [
            'scene_id' => 'scene_nueva',
            'user_message' => 'continua la escena',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('error', 'Selecciona un vault antes de crear una escena nueva.');
    }

    public function test_chat_endpoint_routes_to_continuity_when_continuity_id_is_present(): void
    {
        $this->app->instance(GenerateContinuityTurnUseCase::class, new class
        {
            public function execute(
                string $continuityId,
                string $sceneId,
                string $userMessage,
                string $mode = 'write_scene',
                bool $apply = true,
                ?string $userId = null,
                ?callable $onChunk = null,
                ?callable $onProgress = null,
                ?array $qaLoop = null,
            ): array {
                return [
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'turnIndex' => 3,
                    'commitId' => 8,
                    'userId' => $userId,
                    'outputMd' => 'Turno continuidad',
                ];
            }
        });

        $response = $this->postJson('/api/v2/chat', [
            'continuity_id' => 'cont_demo',
            'scene_id' => 'scene_demo',
            'user_message' => 'sigue',
            'user_id' => 12,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('continuityId', 'cont_demo')
            ->assertJsonPath('turnIndex', 3)
            ->assertJsonPath('userId', 12);
    }

    public function test_chat_stream_endpoint_returns_sse_events_for_continuity_payload(): void
    {
        $this->app->instance(GenerateContinuityTurnUseCase::class, new class
        {
            public function execute(
                string $continuityId,
                string $sceneId,
                string $userMessage,
                string $mode = 'write_scene',
                bool $apply = true,
                ?string $userId = null,
                ?callable $onChunk = null,
                ?callable $onProgress = null,
                ?array $qaLoop = null,
            ): array {
                if ($onChunk !== null) {
                    $onChunk('Primer bloque.');
                    $onChunk("\n\nSegundo bloque.");
                }

                return [
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'turnIndex' => 7,
                    'commitId' => 21,
                    'mode' => $mode,
                    'sceneType' => 'simple',
                    'outputMd' => "Primer bloque.\n\nSegundo bloque.",
                ];
            }
        });

        $response = $this->post('/api/v2/chat/stream', [
            'continuity_id' => 'cont_demo',
            'scene_id' => 'scene_demo',
            'user_message' => 'stream me',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: meta', $content);
        $this->assertStringContainsString('event: chunk', $content);
        $this->assertStringContainsString('event: done', $content);
        $this->assertStringContainsString('"turnIndex":7', $content);
        $this->assertStringContainsString('Primer bloque.', $content);
    }

    public function test_continuity_turn_endpoint_returns_use_case_payload(): void
    {
        $this->app->instance(GenerateContinuityTurnUseCase::class, new class
        {
            public function execute(
                string $continuityId,
                string $sceneId,
                string $userMessage,
                string $mode = 'write_scene',
                bool $apply = true,
                ?string $userId = null,
                ?callable $onChunk = null,
            ): array {
                return [
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'turnIndex' => 4,
                    'commitId' => 10,
                    'outputMd' => 'Turno generado',
                ];
            }
        });

        $response = $this->postJson('/api/v2/continuity/turn', [
            'continuity_id' => 'cont_demo',
            'scene_id' => 'scene_demo',
            'user_message' => 'otro turno',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('turnIndex', 4)
            ->assertJsonPath('commitId', 10);
    }

    public function test_continuity_branch_endpoint_creates_basic_branch(): void
    {
        $this->app->instance(CreateContinuityBranchUseCase::class, new class
        {
            public function execute(string $parentContinuityId, string $newContinuityId, ?string $label = null): array
            {
                return [
                    'continuityId' => $newContinuityId,
                    'parentContinuityId' => $parentContinuityId,
                    'label' => $label,
                    'status' => 'active',
                ];
            }
        });

        $response = $this->postJson('/api/v2/continuity/branch', [
            'parent_continuity_id' => 'cont_root',
            'new_continuity_id' => 'cont_branch',
            'label' => 'branch demo',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('continuityId', 'cont_branch')
            ->assertJsonPath('parentContinuityId', 'cont_root');
    }

    public function test_continuity_checkout_endpoint_returns_restored_state(): void
    {
        $this->app->instance(CheckoutContinuityCommitUseCase::class, new class
        {
            public function execute(string $continuityId, string $sceneId, int $commitId): array
            {
                return [
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'commitId' => $commitId,
                    'restored' => true,
                ];
            }
        });

        $response = $this->postJson('/api/v2/continuity/checkout', [
            'continuity_id' => 'cont_demo',
            'scene_id' => 'scene_demo',
            'commit_id' => 17,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('commitId', 17)
            ->assertJsonPath('restored', true);
    }

    public function test_continuity_rewind_endpoint_returns_restored_turn(): void
    {
        $this->app->instance(RewindContinuityToTurnUseCase::class, new class
        {
            public function execute(string $continuityId, string $sceneId, int $turnIndex): array
            {
                return [
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'turnIndex' => $turnIndex,
                    'restored' => true,
                ];
            }
        });

        $response = $this->postJson('/api/v2/continuity/rewind', [
            'continuity_id' => 'cont_demo',
            'scene_id' => 'scene_demo',
            'turn_index' => 2,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('turnIndex', 2)
            ->assertJsonPath('restored', true);
    }

    public function test_timeline_endpoint_returns_turns_commits_and_state_changes(): void
    {
        DB::table('vaults')->insert([
            'id' => 'vault_demo',
            'name' => 'Vault demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('continuities')->insert([
            'id' => 'cont_demo',
            'parent_id' => null,
            'root_id' => 'cont_demo',
            'label' => 'Demo',
            'status' => 'active',
            'created_at' => now(),
            'archived_at' => null,
        ]);
        DB::table('activities')->insert([
            'id' => 'scene_demo',
            'vault_id' => 'vault_demo',
            'title' => 'Escena demo',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => 'draft',
            'location_id' => null,
            'objective' => null,
            'constraints' => null,
            'draft' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ContinuityTurn::query()->create([
            'continuity_id' => 'cont_demo',
            'activity_id' => 'scene_demo',
            'turn_index' => 1,
            'mode' => 'write_scene',
            'user_message' => 'hola',
            'output_md' => 'salida',
            'notes_json' => '[]',
        ]);

        ContinuityCommit::query()->create([
            'continuity_id' => 'cont_demo',
            'activity_id' => 'scene_demo',
            'parent_commit_id' => null,
            'source_parent_commit_id' => null,
            'turn_index' => 1,
            'mode' => 'write_scene',
            'message' => 'turn 1',
        ]);

        ContinuityStateChange::query()->create([
            'continuity_id' => 'cont_demo',
            'activity_id' => 'scene_demo',
            'kind' => 'character_status',
            'scope_type' => 'character',
            'scope_id' => 'npc_ana',
            'change' => json_encode(['statusKey' => 'stress', 'value' => 55], JSON_THROW_ON_ERROR),
            'severity' => 'medium',
        ]);

        $response = $this->getJson('/api/v2/timeline?scene_id=scene_demo&continuity_id=cont_demo');

        $response
            ->assertOk()
            ->assertJsonPath('sceneId', 'scene_demo')
            ->assertJsonCount(1, 'turns')
            ->assertJsonCount(1, 'commits')
            ->assertJsonCount(1, 'stateChanges')
            ->assertJsonPath('turns.0.turnIndex', 1)
            ->assertJsonPath('commits.0.message', 'turn 1')
            ->assertJsonPath('stateChanges.0.scopeId', 'npc_ana');
    }

    public function test_scene_character_attach_endpoint_imports_character_to_scene(): void
    {
        Vault::query()->create([
            'id' => 'vault_demo',
            'name' => 'Vault demo',
            'status' => 'active',
        ]);

        DB::table('activities')->insert([
            'id' => 'scene_demo',
            'vault_id' => 'vault_demo',
            'title' => 'Escena demo',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => 'draft',
            'location_id' => null,
            'objective' => null,
            'constraints' => null,
            'draft' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Avatar::query()->create([
            'id' => 'ana',
            'name' => 'Ana',
            'vault_id' => 'vault_demo',
        ]);

        $response = $this->postJson('/api/v2/activity/avatars/attach', [
            'scene_id' => 'scene_demo',
            'character_id' => 'ana',
            'role' => 'protagonist',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('activityId', 'scene_demo')
            ->assertJsonPath('avatarId', 'ana')
            ->assertJsonPath('role', 'protagonist')
            ->assertJsonPath('characters.0.id', 'ana');

        $this->assertDatabaseHas('activity_members', [
            'activity_id' => 'scene_demo',
            'avatar_id' => 'ana',
            'role' => 'protagonist',
        ]);
    }
}
