<?php

namespace Tests\Feature\Continuity;

use App\Application\Agents\QuestAgent;
use App\Application\UseCases\ApplyCharacterRuntimeStatusUseCase;
use App\Application\UseCases\ApplyQuestProgressDirectiveUseCase;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\Contracts\AgentGateway;
use App\Application\Contracts\EventEngineRepository;
use App\Application\Contracts\QaLoopRunner;
use App\Application\Contracts\SceneContextBuilder;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRuntimeStatusRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentContinuityRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\Continuity;
use App\Models\ContinuityQuestStatus;
use App\Models\Event;
use App\Models\EventCondition;
use App\Models\EventEffect;
use App\Models\Quest;
use App\Models\QuestStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class MultiQuestIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_handles_multiple_quests_and_event_triggers_in_single_turn(): void
    {
        // 1. Setup Vault, Continuity and Activity
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('v_rpg', 'RPG Vault'));

        \App\Infrastructure\Persistence\Eloquent\Models\LocationRecord::query()->create([
            'id' => 'loc_market',
            'vault_id' => 'v_rpg',
            'name' => 'Mercado',
        ]);

        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 's_market',
            'vaultId' => 'v_rpg',
            'title' => 'Mercado Central',
            'locationId' => 'loc_market',
            'status' => 'active',
            'objective' => 'Investigar el mercado',
            'draft' => "El mercado esta lleno de gente.\nscene_type: complex",
        ]));

        Continuity::query()->create([
            'id' => 'c_main',
            'parent_id' => null,
            'root_id' => 'c_main',
            'label' => 'Main',
            'status' => 'active',
        ]);

        // 2. Setup Quests
        // Quest A: Main Story
        Quest::query()->create([
            'id' => 'q_main',
            'vault_id' => 'v_rpg',
            'title' => 'La Profecía',
            'type' => 'main',
            'status' => 'active',
        ]);
        QuestStep::query()->create(['quest_id' => 'q_main', 'stage_number' => 10, 'description' => 'Llega al mercado']);
        QuestStep::query()->create(['quest_id' => 'q_main', 'stage_number' => 20, 'description' => 'Habla con el mercader']);

        // Quest B: Side Quest (active)
        Quest::query()->create([
            'id' => 'q_side',
            'vault_id' => 'v_rpg',
            'title' => 'Hambre en la ciudad',
            'type' => 'side',
            'status' => 'active',
        ]);
        QuestStep::query()->create(['quest_id' => 'q_side', 'stage_number' => 10, 'description' => 'Busca comida']);
        QuestStep::query()->create(['quest_id' => 'q_side', 'stage_number' => 20, 'description' => 'Comida obtenida']);

        // Initial status
        ContinuityQuestStatus::query()->create([
            'continuity_id' => 'c_main',
            'quest_id' => 'q_main',
            'status' => 'active',
            'current_stage_number' => 10,
        ]);
        ContinuityQuestStatus::query()->create([
            'continuity_id' => 'c_main',
            'quest_id' => 'q_side',
            'status' => 'active',
            'current_stage_number' => 10,
        ]);

        // 3. Setup Events
        // Event that triggers when Quest A reaches stage 20
        $event = Event::query()->create([
            'id' => 'e_merchant_met',
            'vault_id' => 'v_rpg',
            'title' => 'Encuentro con el mercader',
            'status' => 'active',
            'importance' => 50,
        ]);

        $event->locations()->attach('loc_market');

        EventCondition::query()->create([
            'event_id' => 'e_merchant_met',
            'scope_type' => 'quest',
            'operator' => 'eq',
            'value_text' => 'q_main:20',
            'required' => true,
        ]);
        // Effect of this event: Advance Quest B
        EventEffect::query()->create([
            'event_id' => 'e_merchant_met',
            'effect_type' => 'quest_status',
            'change_text' => 'active',
            'payload_json' => [
                'quest_id' => 'q_side',
                'advance_step' => true,
                'new_stage_number' => null, // Should auto-advance to next
            ],
        ]);

        // 4. Mocks
        $agentGateway = $this->createMock(AgentGateway::class);
        $agentGateway->method('generateSceneTurn')->willReturn([
            'outputMd' => 'Hablas con el mercader y te entrega pan para los pobres.',
            'notes' => [],
            'stateChanges' => [],
        ]);

        $questAgent = $this->createMock(QuestAgent::class);
        // Quest Agent decides Quest A advances
        $questAgent->method('evaluate')->willReturn([
            'matched' => true,
            'quest_id' => 'q_main',
            'advance_step' => true,
            'new_stage_number' => 20,
            'new_status' => 'active',
            'ai_summary' => 'Encuentras al mercader.',
            'directive_for_writer' => 'Narra el encuentro.',
        ]);

        // 5. Run Use Case
        /** @var GenerateContinuityTurnUseCase $useCase */
        $useCase = new GenerateContinuityTurnUseCase(
            sceneRepository: new EloquentSceneRepository(),
            sceneContextBuilder: app(SceneContextBuilder::class),
            continuityRepository: new EloquentContinuityRepository(),
            applyCharacterRuntimeStatusUseCase: new ApplyCharacterRuntimeStatusUseCase(
                new EloquentCharacterRuntimeStatusRepository(),
                new ArrayStructuredLogger(),
            ),
            agentGateway: $agentGateway,
            qaLoopRunner: $this->createMock(QaLoopRunner::class),
            logger: new ArrayStructuredLogger(),
            questAgent: $questAgent,
            applyQuestProgressDirectiveUseCase: new ApplyQuestProgressDirectiveUseCase(
                app(\App\Application\Contracts\ContinuityQuestStatusRepository::class),
                new ArrayStructuredLogger(),
            ),
            eventEngineRepository: app(EventEngineRepository::class),
        );

        $result = $useCase->execute(
            continuityId: 'c_main',
            sceneId: 's_market',
            userMessage: 'Busco al mercader para pedirle comida.',
            apply: true,
        );

        // 6. Assertions
        $this->assertTrue($result['applied']);

        // Assert Quest A advanced via QuestAgent
        $this->assertDatabaseHas('continuity_quest_statuses', [
            'continuity_id' => 'c_main',
            'quest_id' => 'q_main',
            'current_stage_number' => 20,
        ]);

        // Assert Quest B advanced via EventEngine (triggered by Quest A advancement)
        // Re-run the event trigger check (it happens inside the execute)
        $this->assertDatabaseHas('continuity_quest_statuses', [
            'continuity_id' => 'c_main',
            'quest_id' => 'q_side',
            'current_stage_number' => 20,
        ]);
        $this->assertCount(1, $result['eventTriggers']['firedEvents']);
        $this->assertEquals('e_merchant_met', $result['eventTriggers']['firedEvents'][0]['eventId']);

        $this->assertDatabaseHas('event_runs', [
            'event_id' => 'e_merchant_met',
            'continuity_id' => 'c_main',
            'fired' => true,
        ]);
    }

    public function test_completes_quest_via_event_trigger(): void
    {
        // 1. Setup
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('v_rpg', 'RPG Vault'));

        \App\Models\Location::query()->create(['id' => 'loc_home', 'vault_id' => 'v_rpg', 'name' => 'Casa']);

        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 's_end',
            'vaultId' => 'v_rpg',
            'title' => 'Final',
            'locationId' => 'loc_home',
            'status' => 'active',
            'objective' => 'Terminar historia',
            'draft' => "Llegas a casa.\nscene_type: complex",
        ]));

        Continuity::query()->create(['id' => 'c_end', 'root_id' => 'c_end', 'label' => 'End', 'status' => 'active']);

        Quest::query()->create(['id' => 'q_final', 'vault_id' => 'v_rpg', 'title' => 'El Final', 'status' => 'active']);
        QuestStep::query()->create(['quest_id' => 'q_final', 'stage_number' => 10, 'description' => 'Ultimo paso']);

        ContinuityQuestStatus::query()->create(['continuity_id' => 'c_end', 'quest_id' => 'q_final', 'status' => 'active', 'current_stage_number' => 10]);

        // 2. Event to complete quest
        $event = Event::query()->create(['id' => 'e_finish', 'vault_id' => 'v_rpg', 'title' => 'Fin', 'status' => 'active', 'importance' => 100]);
        $event->locations()->attach('loc_home');

        EventCondition::query()->create([
            'event_id' => 'e_finish',
            'scope_type' => 'state',
            'operator' => 'contains',
            'value_text' => 'mision cumplida',
            'required' => true,
        ]);

        EventEffect::query()->create([
            'event_id' => 'e_finish',
            'effect_type' => 'quest_status',
            'change_text' => 'completed',
            'payload_json' => ['quest_id' => 'q_final', 'advance_step' => false],
        ]);

        // 3. Mocks
        $agentGateway = $this->createMock(AgentGateway::class);
        $agentGateway->method('generateSceneTurn')->willReturn([
            'outputMd' => '¡Misión cumplida! Todo termina aquí.',
            'notes' => [],
            'stateChanges' => [['scope_type' => 'scene', 'change' => 'mision cumplida', 'severity' => 5]],
        ]);

        // 4. Run
        $useCase = new GenerateContinuityTurnUseCase(
            sceneRepository: new EloquentSceneRepository(),
            sceneContextBuilder: app(SceneContextBuilder::class),
            continuityRepository: new EloquentContinuityRepository(),
            applyCharacterRuntimeStatusUseCase: app(ApplyCharacterRuntimeStatusUseCase::class),
            agentGateway: $agentGateway,
            qaLoopRunner: $this->createMock(QaLoopRunner::class),
            logger: new ArrayStructuredLogger(),
            questAgent: null,
            applyQuestProgressDirectiveUseCase: app(ApplyQuestProgressDirectiveUseCase::class),
            eventEngineRepository: app(EventEngineRepository::class),
        );

        $result = $useCase->execute(continuityId: 'c_end', sceneId: 's_end', userMessage: 'Termino la aventura.', apply: true);

        // 5. Assertions
        $this->assertDatabaseHas('continuity_quest_statuses', [
            'continuity_id' => 'c_end',
            'quest_id' => 'q_final',
            'status' => 'completed',
            'current_stage_number' => 10, // Should stay same but status change
        ]);
        $this->assertCount(1, $result['eventTriggers']['firedEvents']);
    }
}
