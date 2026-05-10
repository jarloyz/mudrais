<?php

namespace Tests\Unit\Application\Services;

use App\Application\Contracts\AgentGateway;
use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\SceneContextBuilder;
use App\Application\Contracts\SceneRepository;
use App\Application\Services\BootstrapSceneService;
use App\Domain\Scene\Activity;
use App\Infrastructure\Persistence\Eloquent\Models\CharacterRecord;
use App\Infrastructure\Persistence\Eloquent\Models\LocationRecord;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;
use App\Models\Continuity;
use App\Models\SceneActiveContinuity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapSceneServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeDomainScene(string $id = 'scene_test'): Activity
    {
        return new Activity(
            id: $id,
            vaultId: 'vault_test',
            title: 'Escena de prueba',
            chapter: 1,
            sceneNumber: 1,
            status: \App\Domains\Matchmaking\Enums\ActivityStatus::RECRUITING->value,
            locationId: 'loc_test',
            objective: null,
            constraints: null,
            draft: '[Sin contenido inicial]',
        );
    }

    private function makeSceneRecord(string $id = 'scene_test'): SceneRecord
    {
        $record = new SceneRecord();
        $record->id = $id;
        $record->vault_id = 'vault_test';
        $record->title = 'Escena de prueba';
        $record->chapter = 1;
        $record->scene_number = 1;
        $record->status = \App\Domains\Matchmaking\Enums\ActivityStatus::RECRUITING->value;
        $record->location_id = 'loc_test';

        $location = new LocationRecord();
        $location->id = 'loc_test';
        $location->entry_premise = 'Un callejón oscuro y húmedo.';
        $record->setRelation('location', $location);

        return $record;
    }

    private function makeNullContinuityRepository(): ContinuityRepository
    {
        return new class implements ContinuityRepository {
            public function requireById(string $continuityId): array { return []; }
            public function createBranch(array $input): array { return []; }
            public function ensureSceneStateFromBase(array $input): void {}
            public function requireSceneState(array $input): array { return []; }
            public function appendSceneDraft(array $input): void {}
            public function replaceSceneDraft(array $input): void {}
            public function nextTurnIndex(array $input): int { return 0; }
            public function appendTurn(array $input): array { return []; }
            public function appendStateChanges(array $input): int { return 0; }
            public function getHeadCommit(array $input): ?array { return null; }
            public function requireCommitById(string $commitId): array { return []; }
            public function requireCommitByTurn(array $input): array { return []; }
            public function checkoutCommit(array $input): array { return []; }
            public function createCommitFromCurrentState(array $input): array { return ['id' => null]; }
            public function createBranchFromCommit(array $input): array { return []; }
            public function setSceneActiveContinuity(array $input): void {}
            public function getActiveSceneContinuity(string $sceneId): ?array { return null; }
        };
    }

    public function test_generates_opening_with_player_character_and_active_continuity(): void
    {
        $domainScene = $this->makeDomainScene();
        $sceneRecord = $this->makeSceneRecord();

        \App\Models\Vault::query()->create(['id' => 'vault_test', 'name' => 'Vault Test', 'status' => 'active']);
        \App\Models\Activity::query()->create([
            'id' => 'scene_test',
            'vault_id' => 'vault_test',
            'title' => 'Escena de prueba',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => \App\Domains\Matchmaking\Enums\ActivityStatus::RECRUITING->value,
        ]);
        Continuity::query()->create([
            'id' => 'cont_test',
            'root_id' => 'cont_test',
            'label' => 'Test',
            'status' => 'active',
        ]);
        SceneActiveContinuity::query()->create([
            'activity_id' => 'scene_test',
            'continuity_id' => 'cont_test',
        ]);

        $charRecord = new CharacterRecord();
        $charRecord->id = 'char_player';
        $charRecord->name = 'Kira';
        $charRecord->public_facade = 'Mercenaria de élite';

        $contextBuilder = new class implements SceneContextBuilder {
            public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array
            {
                return ['scene' => $sceneId, 'continuity' => $continuityId];
            }
        };

        $capturedMessage = '';
        $agentGateway = new class ($capturedMessage) implements AgentGateway {
            public string $captured;

            public function __construct(string &$captured)
            {
                $this->captured = &$captured;
            }

            public function generateSceneTurn(Activity $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
            {
                $this->captured = $userMessage;
                return [
                    'outputMd' => 'La mercenaria Kira entra al callejón. Los NPCs la observan en silencio.',
                    'notes' => [],
                    'stateChanges' => [],
                ];
            }
        };

        $savedScene = null;
        $sceneRepository = new class ($domainScene, $savedScene) implements SceneRepository {
            public ?Activity $saved;

            public function __construct(private Activity $scene, mixed &$saved)
            {
                $this->saved = &$saved;
            }

            public function findById(string $id): ?Activity { return $this->scene; }
            public function save(Activity $scene): void { $this->saved = $scene; }
        };

        $appendedTurns = [];
        $createdCommits = [];
        $continuityRepository = new class ($appendedTurns, $createdCommits) implements ContinuityRepository {
            public array $turns;
            public array $commits;

            public function __construct(array &$turns, array &$commits)
            {
                $this->turns = &$turns;
                $this->commits = &$commits;
            }

            public function requireById(string $continuityId): array { return []; }
            public function createBranch(array $input): array { return []; }
            public function ensureSceneStateFromBase(array $input): void {}
            public function requireSceneState(array $input): array { return []; }
            public function appendSceneDraft(array $input): void {}
            public function replaceSceneDraft(array $input): void {}
            public function nextTurnIndex(array $input): int { return 0; }

            public function appendTurn(array $input): array
            {
                $this->turns[] = $input;
                return [];
            }

            public function appendStateChanges(array $input): int { return 0; }
            public function getHeadCommit(array $input): ?array { return null; }
            public function requireCommitById(string $commitId): array { return []; }
            public function requireCommitByTurn(array $input): array { return []; }
            public function checkoutCommit(array $input): array { return []; }

            public function createCommitFromCurrentState(array $input): array
            {
                $this->commits[] = $input;
                return ['id' => '999'];
            }

            public function createBranchFromCommit(array $input): array { return []; }
            public function setSceneActiveContinuity(array $input): void {}
            public function getActiveSceneContinuity(string $sceneId): ?array { return null; }
        };

        $service = new BootstrapSceneService($contextBuilder, $agentGateway, $sceneRepository, $continuityRepository);
        $result = $service->generateOpening($sceneRecord, $charRecord);

        // El output narrativo se genera con el personaje
        $this->assertStringContainsString('Kira', $result['outputMd']);

        // La escena fue guardada con el draft actualizado
        $this->assertNotNull($savedScene);
        $this->assertStringContainsString('Kira', $savedScene->draft);

        // Se registró el turno de apertura
        $this->assertCount(1, $appendedTurns);
        $this->assertSame('cont_test', $appendedTurns[0]['continuityId']);
        $this->assertSame(0, $appendedTurns[0]['turnIndex']);
        $this->assertSame('bootstrap', $appendedTurns[0]['notes']['type']);

        // Se creó el commit inicial
        $this->assertCount(1, $createdCommits);
        $this->assertSame('999', $result['commitId']);

        // La instrucción de sistema menciona al jugador y la premisa de la locación
        $this->assertStringContainsString('Kira', $capturedMessage);
        $this->assertStringContainsString('Un callejón oscuro y húmedo.', $capturedMessage);
    }

    public function test_generates_generic_opening_when_no_player_character(): void
    {
        $domainScene = $this->makeDomainScene('scene_no_player');
        $sceneRecord = $this->makeSceneRecord('scene_no_player');

        $contextBuilder = new class implements SceneContextBuilder {
            public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array
            {
                return [];
            }
        };

        $capturedMessage = '';
        $agentGateway = new class ($capturedMessage) implements AgentGateway {
            public string $captured;

            public function __construct(string &$captured)
            {
                $this->captured = &$captured;
            }

            public function generateSceneTurn(Activity $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
            {
                $this->captured = $userMessage;
                return ['outputMd' => 'El callejón espera en silencio.', 'notes' => [], 'stateChanges' => []];
            }
        };

        $sceneRepository = new class ($domainScene) implements SceneRepository {
            public function __construct(private Activity $scene) {}
            public function findById(string $id): ?Activity { return $this->scene; }
            public function save(Activity $scene): void {}
        };

        $service = new BootstrapSceneService(
            $contextBuilder,
            $agentGateway,
            $sceneRepository,
            $this->makeNullContinuityRepository()
        );

        $result = $service->generateOpening($sceneRecord, null);

        $this->assertSame('El callejón espera en silencio.', $result['outputMd']);
        $this->assertNull($result['commitId']);

        // El mensaje de sistema es genérico (sin jugador específico)
        $this->assertStringContainsString('No hay un jugador específico', $capturedMessage);
        $this->assertStringContainsString('Un callejón oscuro y húmedo.', $capturedMessage);
    }

    public function test_does_not_save_scene_when_agent_returns_empty_output(): void
    {
        $domainScene = $this->makeDomainScene('scene_empty');
        $sceneRecord = $this->makeSceneRecord('scene_empty');

        $contextBuilder = new class implements SceneContextBuilder {
            public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array { return []; }
        };

        $agentGateway = new class implements AgentGateway {
            public function generateSceneTurn(Activity $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
            {
                return ['outputMd' => '  ', 'notes' => [], 'stateChanges' => []];
            }
        };

        $saveCalled = false;
        $sceneRepository = new class ($domainScene, $saveCalled) implements SceneRepository {
            public bool $saveCalled;

            public function __construct(private Activity $scene, bool &$saveCalled)
            {
                $this->saveCalled = &$saveCalled;
            }

            public function findById(string $id): ?Activity { return $this->scene; }
            public function save(Activity $scene): void { $this->saveCalled = true; }
        };

        $service = new BootstrapSceneService(
            $contextBuilder,
            $agentGateway,
            $sceneRepository,
            $this->makeNullContinuityRepository()
        );

        $result = $service->generateOpening($sceneRecord, null);

        $this->assertSame('', $result['outputMd']);
        $this->assertFalse($saveCalled, 'No debe guardar la escena si el agente no devuelve contenido');
        $this->assertNull($result['commitId']);
    }
}
