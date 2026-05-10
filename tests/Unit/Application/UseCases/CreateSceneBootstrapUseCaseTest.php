<?php

namespace Tests\Unit\Application\UseCases;

use App\Application\Agents\QuestScaffolderAgent;
use App\Application\UseCases\ApplyQuestProgressDirectiveUseCase;
use App\Application\UseCases\CreateSceneBootstrapUseCase;
use App\Domain\Catalog\Location;
use App\Domain\Catalog\Vault;
use App\Infrastructure\Persistence\Eloquent\EloquentLocationRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\Continuity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class CreateSceneBootstrapUseCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_scene_with_location_generated_quest_and_initial_status(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_boot', 'Vault Bootstrap'));
        (new EloquentLocationRepository())->save(new Location(
            id: 'loc_safehouse',
            vaultId: 'vault_boot',
            name: 'Safehouse',
        ));
        Continuity::query()->create([
            'id' => 'cont_boot',
            'parent_id' => null,
            'root_id' => 'cont_boot',
            'label' => 'Bootstrap',
            'status' => 'active',
        ]);

        $useCase = new CreateSceneBootstrapUseCase(
            vaultContextRepository: $vaultRepository,
            locationRepository: new EloquentLocationRepository(),
            sceneRepository: new EloquentSceneRepository(),
            questScaffoldingRepository: app(\App\Application\Contracts\QuestScaffoldingRepository::class),
            questScaffolderAgent: new class implements QuestScaffolderAgent
            {
                public function generate(string $prompt, ?string $userId = null): array
                {
                    return [
                        'title' => 'Escape inicial',
                        'description' => 'Salir del escondite.',
                        'type' => 'main',
                        'status' => 'active',
                        'steps' => [
                            ['stage_number' => 10, 'description' => 'Revisa la salida.', 'is_optional' => false],
                            ['stage_number' => 20, 'description' => 'Evita al vigilante.', 'is_optional' => false],
                            ['stage_number' => 30, 'description' => 'Cruza al exterior.', 'is_optional' => false],
                        ],
                    ];
                }
            },
            applyQuestProgressDirectiveUseCase: new ApplyQuestProgressDirectiveUseCase(
                app(\App\Application\Contracts\ContinuityQuestStatusRepository::class),
                new ArrayStructuredLogger(),
            ),
            continuityQuestStatusRepository: app(\App\Application\Contracts\ContinuityQuestStatusRepository::class),
            logger: new ArrayStructuredLogger(),
        );

        $result = $useCase->execute([
            'scene_id' => 'scene_boot',
            'vault_id' => 'vault_boot',
            'location_id' => 'loc_safehouse',
            'title' => 'Escena bootstrap',
            'quest_prompt' => 'El protagonista debe escapar del escondite.',
            'continuity_id' => 'cont_boot',
        ]);

        $this->assertTrue($result['sceneCreated']);
        $this->assertSame('loc_safehouse', $result['scene']['location_id']);
        $this->assertSame('Escape inicial', $result['quest']['title']);
        $this->assertTrue($result['quest']['generated']);
        $this->assertTrue($result['questStatusSeed']['applied']);
        $this->assertDatabaseHas('activities', [
            'id' => 'scene_boot',
            'vault_id' => 'vault_boot',
            'location_id' => 'loc_safehouse',
        ]);
        $this->assertDatabaseHas('quests', [
            'vault_id' => 'vault_boot',
            'title' => 'Escape inicial',
        ]);
        $this->assertDatabaseHas('continuity_quest_statuses', [
            'continuity_id' => 'cont_boot',
            'activity_id' => 'scene_boot',
            'current_stage_number' => 10,
            'status' => 'active',
        ]);
    }
}
