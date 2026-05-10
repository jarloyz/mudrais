<?php

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\CreateSceneBootstrapUseCase;
use App\Application\UseCases\CreateVaultStarterPackUseCase;
use App\Application\Agents\QuestScaffolderAgent;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class CreateVaultStarterPackUseCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_default_location_scene_and_quest_for_empty_vault(): void
    {
        app()->bind(QuestScaffolderAgent::class, fn () => new class implements QuestScaffolderAgent
        {
            public function generate(string $prompt, ?string $userId = null): array
            {
                return [
                    'title' => 'Quest inicial',
                    'description' => 'Quest inicial',
                    'type' => 'main',
                    'status' => 'active',
                    'steps' => [
                        ['stage_number' => 10, 'description' => 'Define el peligro.', 'is_optional' => false],
                        ['stage_number' => 20, 'description' => 'Supera el obstaculo.', 'is_optional' => false],
                        ['stage_number' => 30, 'description' => 'Asegura una salida.', 'is_optional' => false],
                    ],
                ];
            }
        });

        $vault = Vault::query()->create([
            'id' => 'vault_seed',
            'name' => 'Vault Seed',
            'status' => 'active',
            'description' => 'Un refugio decadente donde todo empieza.',
        ]);

        $useCase = new CreateVaultStarterPackUseCase(
            locationRepository: app(\App\Application\Contracts\LocationRepository::class),
            createSceneBootstrapUseCase: app(CreateSceneBootstrapUseCase::class),
            logger: new ArrayStructuredLogger(),
        );

        $result = $useCase->execute($vault);

        $this->assertTrue($result['created']);
        $this->assertSame('inicio', $result['location']['id']);
        $this->assertDatabaseHas('locations', [
            'id' => 'inicio',
            'vault_id' => 'vault_seed',
        ]);
        $this->assertDatabaseHas('activities', [
            'id' => 'escena_inicial',
            'vault_id' => 'vault_seed',
            'location_id' => 'inicio',
        ]);
        $this->assertDatabaseHas('quests', [
            'vault_id' => 'vault_seed',
            'title' => 'Quest inicial',
        ]);
    }

    public function test_skips_starter_pack_when_vault_already_has_seed_content(): void
    {
        $vault = Vault::query()->create([
            'id' => 'vault_seed',
            'name' => 'Vault Seed',
            'status' => 'active',
        ]);
        \DB::table('locations')->insert([
            'id' => 'inicio',
            'vault_id' => 'vault_seed',
            'name' => 'Inicio',
            'context_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $useCase = new CreateVaultStarterPackUseCase(
            locationRepository: app(\App\Application\Contracts\LocationRepository::class),
            createSceneBootstrapUseCase: app(CreateSceneBootstrapUseCase::class),
            logger: new ArrayStructuredLogger(),
        );

        $result = $useCase->execute($vault);

        $this->assertFalse($result['created']);
        $this->assertSame('already_seeded', $result['reason']);
    }
}
