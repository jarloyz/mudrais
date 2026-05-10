<?php

namespace Tests\Feature\Continuity;

use App\Application\UseCases\CheckoutContinuityCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchUseCase;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Infrastructure\Persistence\Eloquent\EloquentContinuityRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\Continuity;
use App\Models\ContinuityCommit;
use App\Models\ContinuityCommitSceneState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class ContinuityBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_branch_creates_child_continuity_with_same_root(): void
    {
        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        $this->seedSceneFixture();
        Continuity::query()->create([
            'id' => 'cont_root',
            'parent_id' => null,
            'root_id' => 'cont_root',
            'label' => 'Root',
            'status' => 'active',
        ]);

        $useCase = new CreateContinuityBranchUseCase(
            new EloquentContinuityRepository(),
            $logger,
        );

        $result = $useCase->execute('cont_root', 'cont_branch', 'Rama principal');

        $this->assertSame('cont_branch', $result['continuityId']);
        $this->assertSame('cont_root', $result['parentContinuityId']);
        $this->assertSame('cont_root', $result['rootContinuityId']);
        $this->assertSame('Rama principal', $result['label']);
        $this->assertDatabaseHas('continuities', [
            'id' => 'cont_branch',
            'parent_id' => 'cont_root',
            'root_id' => 'cont_root',
            'label' => 'Rama principal',
            'status' => 'active',
        ]);
        $this->assertTrue(collect($entries)->contains(fn (array $entry): bool => $entry['message'] === 'Inicio de creacion de branch de continuidad'));
        $this->assertTrue(collect($entries)->contains(function (array $entry): bool {
            return $entry['message'] === 'Branch de continuidad creado'
                && ($entry['context']['continuityId'] ?? null) === 'cont_branch';
        }));
    }

    public function test_checkout_commit_sets_active_continuity_for_scene(): void
    {
        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        $this->seedSceneFixture();

        Continuity::query()->create([
            'id' => 'cont_root',
            'parent_id' => null,
            'root_id' => 'cont_root',
            'label' => 'Root',
            'status' => 'active',
        ]);
        $commit = ContinuityCommit::query()->create([
            'continuity_id' => 'cont_root',
            'activity_id' => 'scene_cont',
            'turn_index' => 1,
            'mode' => 'write_scene',
            'message' => 'primer commit',
        ]);
        ContinuityCommitSceneState::query()->create([
            'commit_id' => $commit->id,
            'continuity_id' => 'cont_root',
            'activity_id' => 'scene_cont',
            'objective' => null,
            'constraints' => null,
            'draft' => 'Draft base continuidad',
        ]);

        $useCase = new CheckoutContinuityCommitUseCase(
            new EloquentContinuityRepository(),
            $logger,
        );

        $result = $useCase->execute('cont_root', 'scene_cont', (int) $commit->id);

        $this->assertTrue($result['restored']);
        $this->assertSame('cont_root', $result['sceneState']['continuity_id']);
        $this->assertSame((int) $commit->id, $result['sceneState']['continuity_commit_id']);
        $this->assertSame('Draft base continuidad', $result['sceneState']['draft']);
        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'scene_cont',
            'continuity_id' => 'cont_root',
            'continuity_commit_id' => $commit->id,
        ]);
        $this->assertTrue(collect($entries)->contains(fn (array $entry): bool => $entry['message'] === 'Inicio de checkout de commit de continuidad'));
        $this->assertTrue(collect($entries)->contains(function (array $entry) use ($commit): bool {
            return $entry['message'] === 'Checkout de continuidad completado'
                && ($entry['context']['sceneId'] ?? null) === 'scene_cont'
                && ($entry['context']['commitId'] ?? null) === (int) $commit->id;
        }));
    }

    private function seedSceneFixture(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_cont', 'Vault Continuidad'));

        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 'scene_cont',
            'vaultId' => 'vault_cont',
            'title' => 'Escena continuidad',
            'chapter' => 1,
            'sceneNumber' => 1,
            'status' => 'draft',
            'locationId' => null,
            'objective' => null,
            'constraints' => null,
            'draft' => 'Borrador inicial continuidad',
            'characters' => [],
        ]));
    }
}
