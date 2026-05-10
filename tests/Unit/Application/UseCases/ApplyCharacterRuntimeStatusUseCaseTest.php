<?php

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\ApplyCharacterRuntimeStatusUseCase;
use App\Domain\Catalog\Avatar;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRuntimeStatusRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\Continuity;
use App\Models\CharacterRuntimeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class ApplyCharacterRuntimeStatusUseCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_upserts_runtime_status_from_state_changes(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_runtime', 'Vault Runtime'));
        Continuity::query()->create([
            'id' => 'cont_runtime',
            'parent_id' => null,
            'root_id' => 'cont_runtime',
            'label' => 'Runtime',
            'status' => 'active',
        ]);
        (new EloquentCharacterRepository())->save(new Avatar(
            id: 'ana',
            vaultId: 'vault_runtime',
            name: 'Ana',
        ));
        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 'scene_runtime',
            'vaultId' => 'vault_runtime',
            'title' => 'Escena runtime',
            'draft' => 'Borrador runtime',
            'characters' => [],
        ]));

        $useCase = new ApplyCharacterRuntimeStatusUseCase(
            new EloquentCharacterRuntimeStatusRepository(),
            new ArrayStructuredLogger(),
        );

        $result = $useCase->execute(
            continuityId: 'cont_runtime',
            sceneId: 'scene_runtime',
            userId: null,
            stateChanges: [
                ['scope_type' => 'character', 'scope_id' => 'ana', 'change' => 'stress: 25, emotion: nerviosa', 'severity' => 2],
                ['scope_type' => 'character', 'scope_id' => 'ana', 'change' => 'energy: 80', 'severity' => 1],
            ],
            characterContext: [
                ['id' => 'ana', 'name' => 'Ana'],
            ],
            turnIndex: 1,
        );

        $this->assertSame(3, $result['appliedCount']);
        $this->assertDatabaseHas('character_runtime_status', [
            'continuity_id' => 'cont_runtime',
            'activity_id' => 'scene_runtime',
            'character_id' => 'ana',
            'status_key' => 'stress',
            'status_value' => 25,
        ]);
        $this->assertDatabaseHas('character_runtime_status', [
            'continuity_id' => 'cont_runtime',
            'activity_id' => 'scene_runtime',
            'character_id' => 'ana',
            'status_key' => 'emotion',
            'status_text' => 'nerviosa',
        ]);
    }

    public function test_execute_updates_existing_runtime_status_with_latest_value(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_runtime', 'Vault Runtime'));
        Continuity::query()->create([
            'id' => 'cont_runtime',
            'parent_id' => null,
            'root_id' => 'cont_runtime',
            'label' => 'Runtime',
            'status' => 'active',
        ]);
        (new EloquentCharacterRepository())->save(new Avatar(
            id: 'ana',
            vaultId: 'vault_runtime',
            name: 'Ana',
        ));
        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 'scene_runtime',
            'vaultId' => 'vault_runtime',
            'title' => 'Escena runtime',
            'draft' => 'Borrador runtime',
            'characters' => [],
        ]));

        CharacterRuntimeStatus::query()->create([
            'continuity_id' => 'cont_runtime',
            'activity_id' => 'scene_runtime',
            'user_id' => null,
            'character_id' => 'ana',
            'status_key' => 'stress',
            'status_value' => 10,
            'status_text' => null,
            'unit' => 'percent',
            'source' => 'system',
        ]);

        $useCase = new ApplyCharacterRuntimeStatusUseCase(
            new EloquentCharacterRuntimeStatusRepository(),
            new ArrayStructuredLogger(),
        );

        $useCase->execute(
            continuityId: 'cont_runtime',
            sceneId: 'scene_runtime',
            userId: null,
            stateChanges: [
                ['scope_type' => 'character', 'scope_id' => 'ana', 'change' => 'stress: 42', 'severity' => 1],
            ],
            characterContext: [
                ['id' => 'ana', 'name' => 'Ana'],
            ],
            turnIndex: 2,
        );

        $this->assertSame(1, CharacterRuntimeStatus::query()->count());
        $this->assertDatabaseHas('character_runtime_status', [
            'continuity_id' => 'cont_runtime',
            'activity_id' => 'scene_runtime',
            'character_id' => 'ana',
            'status_key' => 'stress',
            'status_value' => 42,
        ]);
    }
}
