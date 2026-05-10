<?php

namespace Tests\Feature\Services;

use App\Application\Contracts\AgentGateway;
use App\Application\Contracts\SceneContextBuilder;
use App\Application\Contracts\SceneRepository;
use App\Application\Services\CharacterSnapshotService;
use App\Application\Services\TurnProcessorService;
use App\Domain\Scene\Activity as DomainScene;
use App\Models\Avatar;
use App\Models\CharacterInstance;
use App\Models\CharacterInventory;
use App\Models\Player;
use App\Models\Activity;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class TurnProcessorServiceTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function seedScene(): array
    {
        Vault::query()->create(['id' => 'vault_t', 'name' => 'Vault T', 'status' => 'active']);

        $player = Player::factory()->create();

        Avatar::query()->create(['id' => 'char_t', 'name' => 'Kira', 'vault_id' => 'vault_t']);

        $scene = Activity::query()->create([
            'id' => 'scene_t',
            'vault_id' => 'vault_t',
            'title' => 'Escena T',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => 'ready',
            'draft' => 'Inicio.',
        ]);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_t',
            'avatar_id' => 'char_t',
            'scene_role' => 'player',
            'controlled_by_player_id' => $player->id,
            'initiative_score' => 10,
            'has_acted_this_round' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$scene, $player];
    }

    private function makeDomainScene(): DomainScene
    {
        return new DomainScene(
            id: 'scene_t',
            vaultId: 'vault_t',
            title: 'Escena T',
            chapter: 1,
            sceneNumber: 1,
            status: 'ready',
            locationId: null,
            objective: null,
            constraints: null,
            draft: 'Inicio.',
        );
    }

    private function makeService(
        ?AgentGateway $gateway = null,
        ?SceneContextBuilder $contextBuilder = null,
        ?SceneRepository $sceneRepo = null,
    ): TurnProcessorService {
        $domainScene = $this->makeDomainScene();

        $gateway ??= new class implements AgentGateway {
            public function generateSceneTurn(DomainScene $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
            {
                return ['outputMd' => 'Kira se mueve hacia la puerta.', 'notes' => [], 'stateChanges' => []];
            }
        };

        $contextBuilder ??= new class implements SceneContextBuilder {
            public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array
            {
                return ['characters' => [], 'draft' => 'Inicio.'];
            }
        };

        $sceneRepo ??= new class ($domainScene) implements SceneRepository {
            public function __construct(private DomainScene $scene) {}
            public function findById(string $id): ?DomainScene { return $this->scene; }
            public function save(DomainScene $scene): void {}
        };

        return new TurnProcessorService(
            $sceneRepo,
            $contextBuilder,
            $gateway,
            new CharacterSnapshotService(new ArrayStructuredLogger()),
            new ArrayStructuredLogger(),
        );
    }

    // ─── Tests de validación ──────────────────────────────────────────────────

    public function test_throws_if_scene_not_found(): void
    {
        $this->seedScene();
        $service = $this->makeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Escena no encontrada');

        $service->process([
            'scene_id' => 'no_existe',
            'character_id' => 'char_t',
            'user_id' => 1,
            'user_message' => 'Actúo.',
        ]);
    }

    public function test_throws_if_scene_not_active(): void
    {
        [$scene, $player] = $this->seedScene();
        $scene->update(['status' => 'draft']);
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("no está activa");

        $service->process([
            'scene_id' => 'scene_t',
            'character_id' => 'char_t',
            'user_id' => $player->id,
            'user_message' => 'Actúo.',
        ]);
    }

    public function test_throws_if_user_does_not_control_character(): void
    {
        [$scene, $player] = $this->seedScene();
        $otherPlayer = Player::factory()->create();
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("no controla");

        $service->process([
            'scene_id' => 'scene_t',
            'character_id' => 'char_t',
            'user_id' => $otherPlayer->id,
            'user_message' => 'Actúo.',
        ]);
    }

    public function test_throws_if_not_characters_turn(): void
    {
        [$scene, $player] = $this->seedScene();

        // El personaje debe existir antes de asignarlo como current_turn_character_id (FK)
        Avatar::query()->create(['id' => 'otro_char', 'name' => 'Otro', 'vault_id' => 'vault_t']);
        $scene->update(['current_turn_character_id' => 'otro_char']);
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No es el turno");

        $service->process([
            'scene_id' => 'scene_t',
            'character_id' => 'char_t',
            'user_id' => $player->id,
            'user_message' => 'Actúo.',
        ]);
    }

    public function test_throws_if_character_not_in_scene(): void
    {
        [$scene, $player] = $this->seedScene();
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("no pertenece a la escena");

        $service->process([
            'scene_id' => 'scene_t',
            'character_id' => 'char_inexistente',
            'user_id' => $player->id,
            'user_message' => 'Actúo.',
        ]);
    }

    // ─── Tests de procesamiento ───────────────────────────────────────────────

    public function test_returns_output_without_snapshot(): void
    {
        [$scene, $player] = $this->seedScene();
        $service = $this->makeService();

        $result = $service->process([
            'scene_id' => 'scene_t',
            'character_id' => 'char_t',
            'user_id' => $player->id,
            'user_message' => 'Kira avanza.',
        ]);

        $this->assertSame('scene_t', $result['sceneId']);
        $this->assertSame('char_t', $result['characterId']);
        $this->assertNotEmpty($result['outputMd']);
        $this->assertFalse($result['snapshotUsed']);
        $this->assertNull($result['snapshotVersion']);
    }

    public function test_detects_snapshot_when_available(): void
    {
        [$scene, $player] = $this->seedScene();

        // Crear snapshot para el personaje en la escena
        CharacterInstance::query()->create([
            'activity_id' => 'scene_t',
            'avatar_id' => 'char_t',
            'snapshot_data' => [
                'character_id' => 'char_t',
                'name' => 'Kira',
                'vault_id' => 'vault_t',
                'inventory' => [],
                'bullets' => [],
                'backgrounds' => [],
                'stats' => ['hp' => 100],
                'snapshot_version_note' => 'test',
            ],
            'version' => 2,
            'snapshotted_at' => now(),
        ]);

        $service = $this->makeService();

        $result = $service->process([
            'scene_id' => 'scene_t',
            'character_id' => 'char_t',
            'user_id' => $player->id,
            'user_message' => 'Kira avanza.',
        ]);

        $this->assertTrue($result['snapshotUsed']);
        $this->assertSame(2, $result['snapshotVersion']);
    }

    public function test_context_receives_vtt_metadata(): void
    {
        [$scene, $player] = $this->seedScene();
        $capturedContext = null;

        $gateway = new class ($capturedContext) implements AgentGateway {
            public mixed $ctx;
            public function __construct(mixed &$ctx) { $this->ctx = &$ctx; }
            public function generateSceneTurn(DomainScene $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
            {
                $this->ctx = $context;
                return ['outputMd' => 'ok', 'notes' => [], 'stateChanges' => []];
            }
        };

        $service = $this->makeService(gateway: $gateway);
        $service->process([
            'scene_id' => 'scene_t',
            'character_id' => 'char_t',
            'user_id' => $player->id,
            'user_message' => 'Actúo.',
        ]);

        $this->assertArrayHasKey('vtt', $capturedContext);
        $this->assertSame('char_t', $capturedContext['vtt']['characterId']);
        $this->assertSame($player->id, $capturedContext['vtt']['userId']);
        $this->assertArrayHasKey('snapshotUsed', $capturedContext['vtt']);
    }

    // ─── Tests de EloquentSceneContextBuilder con snapshot ───────────────────

    public function test_context_builder_prefers_snapshot_over_vault_data(): void
    {
        Vault::query()->firstOrCreate(['id' => 'vault_t'], ['name' => 'Vault T', 'status' => 'active']);

        Activity::query()->create([
            'id' => 'scene_ctx',
            'vault_id' => 'vault_t',
            'title' => 'Ctx',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => 'ready',
            'draft' => 'Inicio.',
        ]);

        Avatar::query()->firstOrCreate(['id' => 'char_ctx'], ['name' => 'Vault Name', 'vault_id' => 'vault_t']);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_ctx',
            'avatar_id' => 'char_ctx',
            'scene_role' => 'player',
            'initiative_score' => 0,
            'has_acted_this_round' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Crear snapshot con nombre diferente al del Baúl
        CharacterInstance::query()->create([
            'activity_id' => 'scene_ctx',
            'avatar_id' => 'char_ctx',
            'snapshot_data' => [
                'character_id' => 'char_ctx',
                'name' => 'Snapshot Name',  // distinto al Baúl
                'vault_id' => 'vault_t',
                'inventory' => [
                    ['item_name' => 'Espada', 'category' => 'weapon', 'quantity' => 1, 'is_quick_slot' => true],
                ],
                'bullets' => [
                    ['section' => 'combate', 'content' => 'Experta en esgrima.', 'bullet_type' => 'profile', 'sort_order' => 0],
                ],
                'backgrounds' => [],
                'stats' => ['hp' => 85],
                'snapshot_version_note' => 'test',
            ],
            'version' => 3,
            'snapshotted_at' => now(),
        ]);

        $builder = app(\App\Application\Contracts\SceneContextBuilder::class);
        $context = $builder->build('scene_ctx');

        $char = collect($context['characters'])->firstWhere('id', 'char_ctx');

        // Debe usar el nombre del snapshot
        $this->assertSame('Snapshot Name', $char['name']);
        $this->assertSame(3, $char['snapshot_version']);

        // Debe incluir el inventario del snapshot
        $this->assertCount(1, $char['inventory']);
        $this->assertSame('Espada', $char['inventory'][0]['item_name']);

        // Stats del snapshot
        $this->assertSame(85, $char['profile']['stats']['hp'] ?? null);
    }

    public function test_context_builder_falls_back_to_vault_when_no_snapshot(): void
    {
        Vault::query()->firstOrCreate(['id' => 'vault_t'], ['name' => 'Vault T', 'status' => 'active']);

        Activity::query()->create([
            'id' => 'scene_ctx2',
            'vault_id' => 'vault_t',
            'title' => 'Ctx2',
            'chapter' => 1,
            'scene_number' => 2,
            'status' => 'ready',
            'draft' => 'Inicio.',
        ]);

        Avatar::query()->firstOrCreate(['id' => 'char_ctx2'], ['name' => 'Vault Only', 'vault_id' => 'vault_t']);

        DB::table('activity_members')->insert([
            'activity_id' => 'scene_ctx2',
            'avatar_id' => 'char_ctx2',
            'scene_role' => 'npc',
            'initiative_score' => 0,
            'has_acted_this_round' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $builder = app(\App\Application\Contracts\SceneContextBuilder::class);
        $context = $builder->build('scene_ctx2');

        $char = collect($context['characters'])->firstWhere('id', 'char_ctx2');

        $this->assertSame('Vault Only', $char['name']);
        $this->assertNull($char['snapshot_version']);
    }
}
