<?php

namespace Tests\Feature\Continuity;

use App\Application\Agents\QuestAgent;
use App\Application\UseCases\ApplyCharacterRuntimeStatusUseCase;
use App\Application\UseCases\ApplyQuestProgressDirectiveUseCase;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\Contracts\QaLoopRunner;
use App\Domain\Catalog\Avatar;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Domain\Scene\SceneCharacter;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRuntimeStatusRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentContinuityRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneContextBuilder;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\CharacterRuntimeStatus;
use App\Models\Continuity;
use App\Models\ContinuityCommitSceneState;
use App\Models\ContinuityQuestStatus;
use App\Models\ContinuitySceneState;
use App\Models\ContinuityStateChange;
use App\Models\Quest;
use App\Models\QuestStep;
use App\Models\ContinuityTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class GenerateContinuityTurnUseCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_and_persists_continuity_turn_with_commit_and_runtime(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_cont', 'Vault Continuidad'));
        (new EloquentCharacterRepository())->save(new Avatar(
            id: 'ana',
            vaultId: 'vault_cont',
            name: 'Ana',
        ));
        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 'scene_cont',
            'vaultId' => 'vault_cont',
            'title' => 'Escena continuidad',
            'objective' => 'Objetivo base',
            'draft' => 'Borrador base',
            'characters' => [new SceneCharacter('ana', 'protagonist')],
        ]));
        Continuity::query()->create([
            'id' => 'cont_main',
            'parent_id' => null,
            'root_id' => 'cont_main',
            'label' => 'Main',
            'status' => 'active',
        ]);

        $agentGateway = new class implements \App\Application\Contracts\AgentGateway
        {
            public function generateSceneTurn(Activity $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
            {
                if ($onChunk !== null) {
                    $onChunk('Ana avanzo un paso y apretó la mandíbula.');
                }

                return [
                    'outputMd' => 'Ana avanzo un paso y apretó la mandíbula.',
                    'notes' => ['nota continuidad'],
                    'stateChanges' => [
                        [
                            'scope_type' => 'character',
                            'scope_id' => 'ana',
                            'change' => 'stress: 33, emotion: tensa',
                            'severity' => 2,
                        ],
                    ],
                ];
            }
        };

        $useCase = new GenerateContinuityTurnUseCase(
            sceneRepository: new EloquentSceneRepository(),
            sceneContextBuilder: new EloquentSceneContextBuilder(new EloquentCharacterRuntimeStatusRepository()),
            continuityRepository: new EloquentContinuityRepository(),
            applyCharacterRuntimeStatusUseCase: new ApplyCharacterRuntimeStatusUseCase(
                new EloquentCharacterRuntimeStatusRepository(),
                new ArrayStructuredLogger(),
            ),
            agentGateway: $agentGateway,
            qaLoopRunner: new class implements QaLoopRunner
            {
                public function run(Activity $scene, array $context, string $userMessage, string $mode, string $outputMd, array $qaLoop, ?string $userId = null): array
                {
                    return [
                        'enabled' => false,
                        'triggered' => false,
                        'passes' => 0,
                        'highestSeverity' => 'none',
                        'status' => 'disabled',
                        'issues' => [],
                        'outputMd' => $outputMd,
                    ];
                }
            },
            logger: new ArrayStructuredLogger(),
            questAgent: null,
            applyQuestProgressDirectiveUseCase: new ApplyQuestProgressDirectiveUseCase(
                app(\App\Application\Contracts\ContinuityQuestStatusRepository::class),
                new ArrayStructuredLogger(),
            ),
        );

        $result = $useCase->execute(
            continuityId: 'cont_main',
            sceneId: 'scene_cont',
            userMessage: 'Continua la escena',
            mode: 'write_scene',
            apply: true,
            userId: null,
        );

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['turnIndex']);
        $this->assertNotNull($result['commitId']);
        $this->assertSame('simple', $result['sceneType']);
        $this->assertDatabaseHas('continuity_scene_states', [
            'continuity_id' => 'cont_main',
            'activity_id' => 'scene_cont',
        ]);
        $this->assertDatabaseHas('continuity_turns', [
            'continuity_id' => 'cont_main',
            'activity_id' => 'scene_cont',
            'turn_index' => 1,
        ]);
        $this->assertDatabaseHas('continuity_state_changes', [
            'continuity_id' => 'cont_main',
            'activity_id' => 'scene_cont',
            'scope_type' => 'character',
        ]);
        $this->assertDatabaseHas('character_runtime_status', [
            'continuity_id' => 'cont_main',
            'activity_id' => 'scene_cont',
            'character_id' => 'ana',
            'status_key' => 'stress',
            'status_value' => 33,
        ]);

        $sceneState = ContinuitySceneState::query()
            ->where('continuity_id', 'cont_main')
            ->where('activity_id', 'scene_cont')
            ->first();
        $this->assertNotNull($sceneState);
        $this->assertStringContainsString('Borrador base', (string) $sceneState->draft);
        $this->assertStringContainsString('Ana avanzo un paso', (string) $sceneState->draft);

        $commitSnapshot = ContinuityCommitSceneState::query()
            ->where('commit_id', $result['commitId'])
            ->where('activity_id', 'scene_cont')
            ->first();
        $this->assertNotNull($commitSnapshot);
        $this->assertStringContainsString('Ana avanzo un paso', (string) $commitSnapshot->draft);
        $this->assertSame(1, ContinuityTurn::query()->count());
        $this->assertSame(1, ContinuityStateChange::query()->count());
        $this->assertSame(2, CharacterRuntimeStatus::query()->count());
    }

    public function test_applies_quest_directive_before_persisting_turn(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_cont', 'Vault Continuidad'));
        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 'scene_cont',
            'vaultId' => 'vault_cont',
            'title' => 'Escena continuidad',
            'objective' => 'Escapar',
            'draft' => 'Borrador base',
        ]));
        Continuity::query()->create([
            'id' => 'cont_main',
            'parent_id' => null,
            'root_id' => 'cont_main',
            'label' => 'Main',
            'status' => 'active',
        ]);
        Quest::query()->create([
            'id' => 'quest_escape',
            'vault_id' => 'vault_cont',
            'title' => 'Fuga del refugio',
            'description' => 'Escapar',
            'type' => 'main',
            'status' => 'active',
        ]);
        QuestStep::query()->create([
            'quest_id' => 'quest_escape',
            'stage_number' => 20,
            'description' => 'Mata al guardia',
            'is_optional' => false,
        ]);
        QuestStep::query()->create([
            'quest_id' => 'quest_escape',
            'stage_number' => 30,
            'description' => 'Cruza la salida',
            'is_optional' => false,
        ]);
        ContinuityQuestStatus::query()->create([
            'continuity_id' => 'cont_main',
            'activity_id' => 'scene_cont',
            'quest_id' => 'quest_escape',
            'status' => 'active',
            'current_stage_number' => 20,
            'ai_summary' => 'El guardia bloquea la salida.',
        ]);

        $useCase = new GenerateContinuityTurnUseCase(
            sceneRepository: new EloquentSceneRepository(),
            sceneContextBuilder: app(\App\Application\Contracts\SceneContextBuilder::class),
            continuityRepository: new EloquentContinuityRepository(),
            applyCharacterRuntimeStatusUseCase: new ApplyCharacterRuntimeStatusUseCase(
                new EloquentCharacterRuntimeStatusRepository(),
                new ArrayStructuredLogger(),
            ),
            agentGateway: new class implements \App\Application\Contracts\AgentGateway
            {
                public function generateSceneTurn(Activity $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
                {
                    return [
                        'outputMd' => 'El guardia cae y la salida queda despejada.',
                        'notes' => [],
                        'stateChanges' => [],
                    ];
                }
            },
            qaLoopRunner: new class implements QaLoopRunner
            {
                public function run(Activity $scene, array $context, string $userMessage, string $mode, string $outputMd, array $qaLoop, ?string $userId = null): array
                {
                    return [
                        'enabled' => false,
                        'triggered' => false,
                        'passes' => 0,
                        'highestSeverity' => 'none',
                        'status' => 'disabled',
                        'issues' => [],
                        'outputMd' => $outputMd,
                    ];
                }
            },
            logger: new ArrayStructuredLogger(),
            questAgent: new class implements QuestAgent
            {
                public function evaluate(Activity $scene, array $context, string $userMessage, ?string $userId = null): array
                {
                    return [
                        'matched' => true,
                        'quest_id' => 'quest_escape',
                        'advance_step' => true,
                        'new_stage_number' => 30,
                        'new_status' => 'active',
                        'ai_summary' => 'El guardia cae y la ruta queda libre.',
                        'directive_for_writer' => 'Narra la victoria inmediata.',
                        'confidence' => 0.93,
                    ];
                }
            },
            applyQuestProgressDirectiveUseCase: new ApplyQuestProgressDirectiveUseCase(
                app(\App\Application\Contracts\ContinuityQuestStatusRepository::class),
                new ArrayStructuredLogger(),
            ),
        );

        $result = $useCase->execute(
            continuityId: 'cont_main',
            sceneId: 'scene_cont',
            userMessage: 'Le disparo al guardia.',
            mode: 'write_scene',
            apply: true,
        );

        $this->assertTrue($result['questDirective']['matched']);
        $this->assertTrue($result['questUpdateSummary']['applied']);
        $this->assertDatabaseHas('continuity_quest_statuses', [
            'continuity_id' => 'cont_main',
            'quest_id' => 'quest_escape',
            'current_stage_number' => 30,
            'status' => 'active',
        ]);
    }
}
