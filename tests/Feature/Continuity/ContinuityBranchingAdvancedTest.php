<?php

namespace Tests\Feature\Continuity;

use App\Application\UseCases\CreateContinuityBranchFromCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromTurnUseCase;
use App\Application\UseCases\RewindContinuityToTurnUseCase;
use App\Application\UseCases\SwitchSceneBranchUseCase;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Infrastructure\Persistence\Eloquent\EloquentContinuityRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\Continuity;
use App\Models\ContinuityCommit;
use App\Models\ContinuityCommitSceneState;
use App\Models\ContinuitySceneState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class ContinuityBranchingAdvancedTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_branch_from_commit_creates_new_head_and_snapshot(): void
    {
        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        [$commit] = $this->seedContinuityFixture();

        $useCase = new CreateContinuityBranchFromCommitUseCase(
            new EloquentContinuityRepository(),
            $logger,
        );

        $result = $useCase->execute('cont_root', 'cont_alt', 'scene_cont', (int) $commit->id, 'Alt desde commit');

        $this->assertSame('cont_alt', $result['continuityId']);
        $this->assertSame((int) $commit->id, $result['sourceCommitId']);
        $this->assertNotNull($result['headCommitId']);
        $this->assertDatabaseHas('continuity_scene_states', [
            'continuity_id' => 'cont_alt',
            'activity_id' => 'scene_cont',
            'draft' => 'Draft turn 1',
        ]);
        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'scene_cont',
            'continuity_id' => 'cont_alt',
            'continuity_commit_id' => $result['headCommitId'],
        ]);
    }

    public function test_create_branch_from_turn_resolves_commit_by_turn(): void
    {
        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        [, $secondCommit] = $this->seedContinuityFixture();

        $useCase = new CreateContinuityBranchFromTurnUseCase(
            new EloquentContinuityRepository(),
            $logger,
        );

        $result = $useCase->execute('cont_root', 'cont_turn', 'scene_cont', 2, 'Alt desde turno');

        $this->assertSame(2, $result['sourceTurnIndex']);
        $this->assertSame((int) $secondCommit->id, $result['sourceCommitId']);
        $this->assertDatabaseHas('continuity_scene_states', [
            'continuity_id' => 'cont_turn',
            'activity_id' => 'scene_cont',
            'draft' => 'Draft turn 2',
        ]);
    }

    public function test_rewind_to_turn_restores_scene_snapshot(): void
    {
        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        [$firstCommit, $secondCommit] = $this->seedContinuityFixture();

        ContinuitySceneState::query()->updateOrCreate(
            ['continuity_id' => 'cont_root', 'activity_id' => 'scene_cont'],
            ['objective' => 'obj', 'constraints' => 'cons', 'draft' => 'Draft actual'],
        );

        $useCase = new RewindContinuityToTurnUseCase(
            new EloquentContinuityRepository(),
            $logger,
        );

        $result = $useCase->execute('cont_root', 'scene_cont', 1);

        $this->assertSame((int) $firstCommit->id, $result['commitId']);
        $this->assertSame('Draft turn 1', $result['sceneState']['draft']);
        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'scene_cont',
            'continuity_id' => 'cont_root',
            'continuity_commit_id' => $firstCommit->id,
        ]);
        $this->assertDatabaseMissing('scene_active_continuities', [
            'activity_id' => 'scene_cont',
            'continuity_commit_id' => $secondCommit->id,
        ]);
    }

    public function test_switch_scene_branch_points_scene_to_selected_continuity(): void
    {
        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        [$firstCommit] = $this->seedContinuityFixture();
        Continuity::query()->create([
            'id' => 'cont_branch',
            'parent_id' => 'cont_root',
            'root_id' => 'cont_root',
            'label' => 'Branch',
            'status' => 'active',
        ]);
        $branchCommit = ContinuityCommit::query()->create([
            'continuity_id' => 'cont_branch',
            'activity_id' => 'scene_cont',
            'turn_index' => 1,
            'mode' => 'write_scene',
            'message' => 'branch commit',
        ]);
        ContinuitySceneState::query()->create([
            'continuity_id' => 'cont_branch',
            'activity_id' => 'scene_cont',
            'objective' => 'obj',
            'constraints' => 'cons',
            'draft' => 'Draft branch',
        ]);
        ContinuityCommitSceneState::query()->create([
            'commit_id' => $branchCommit->id,
            'continuity_id' => 'cont_branch',
            'activity_id' => 'scene_cont',
            'objective' => 'obj',
            'constraints' => 'cons',
            'draft' => 'Draft branch',
        ]);

        $useCase = new SwitchSceneBranchUseCase(
            new EloquentContinuityRepository(),
            $logger,
        );

        $result = $useCase->execute('scene_cont', 'cont_branch');

        $this->assertTrue($result['switched']);
        $this->assertSame('cont_branch', $result['continuityId']);
        $this->assertDatabaseHas('scene_active_continuities', [
            'activity_id' => 'scene_cont',
            'continuity_id' => 'cont_branch',
            'continuity_commit_id' => $branchCommit->id,
        ]);
        $this->assertNotSame($firstCommit->id, $branchCommit->id);
    }

    /**
     * @return array{0: ContinuityCommit, 1: ContinuityCommit}
     */
    private function seedContinuityFixture(): array
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

        Continuity::query()->create([
            'id' => 'cont_root',
            'parent_id' => null,
            'root_id' => 'cont_root',
            'label' => 'Root',
            'status' => 'active',
        ]);

        $firstCommit = ContinuityCommit::query()->create([
            'continuity_id' => 'cont_root',
            'activity_id' => 'scene_cont',
            'turn_index' => 1,
            'mode' => 'write_scene',
            'message' => 'turn 1',
        ]);
        $secondCommit = ContinuityCommit::query()->create([
            'continuity_id' => 'cont_root',
            'activity_id' => 'scene_cont',
            'parent_commit_id' => $firstCommit->id,
            'turn_index' => 2,
            'mode' => 'write_scene',
            'message' => 'turn 2',
        ]);

        ContinuityCommitSceneState::query()->create([
            'commit_id' => $firstCommit->id,
            'continuity_id' => 'cont_root',
            'activity_id' => 'scene_cont',
            'objective' => 'obj',
            'constraints' => 'cons',
            'draft' => 'Draft turn 1',
        ]);
        ContinuityCommitSceneState::query()->create([
            'commit_id' => $secondCommit->id,
            'continuity_id' => 'cont_root',
            'activity_id' => 'scene_cont',
            'objective' => 'obj',
            'constraints' => 'cons',
            'draft' => 'Draft turn 2',
        ]);

        ContinuitySceneState::query()->create([
            'continuity_id' => 'cont_root',
            'activity_id' => 'scene_cont',
            'objective' => 'obj',
            'constraints' => 'cons',
            'draft' => 'Draft turn 2',
        ]);

        return [$firstCommit, $secondCommit];
    }
}
