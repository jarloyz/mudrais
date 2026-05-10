<?php

namespace Tests\Feature\Continuity;

use App\Domain\Catalog\Avatar;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Domain\Scene\SceneCharacter;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRuntimeStatusRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentContinuityQuestStatusRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneContextBuilder;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\CharacterRuntimeStatus;
use App\Models\Continuity;
use App\Models\ContinuityQuestStatus;
use App\Models\Quest;
use App\Models\QuestStep;
use App\Models\SceneActiveContinuity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeStatusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scene_context_builder_includes_runtime_status_for_active_continuity(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_runtime', 'Vault Runtime'));
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
            'characters' => [new SceneCharacter('ana', 'protagonist')],
        ]));

        $user = User::factory()->create();
        Continuity::query()->create([
            'id' => 'cont_runtime',
            'parent_id' => null,
            'root_id' => 'cont_runtime',
            'label' => 'Runtime',
            'status' => 'active',
        ]);
        SceneActiveContinuity::query()->create([
            'activity_id' => 'scene_runtime',
            'continuity_id' => 'cont_runtime',
            'continuity_commit_id' => null,
        ]);
        CharacterRuntimeStatus::query()->create([
            'continuity_id' => 'cont_runtime',
            'activity_id' => 'scene_runtime',
            'user_id' => $user->id,
            'character_id' => 'ana',
            'status_key' => 'stress',
            'status_value' => 35,
            'status_text' => null,
            'unit' => 'percent',
            'source' => 'system',
        ]);

        $context = (new EloquentSceneContextBuilder(
            new EloquentCharacterRuntimeStatusRepository(),
            new EloquentContinuityQuestStatusRepository(),
        ))->build('scene_runtime', 'cont_runtime', $user->id);

        $this->assertCount(1, $context['characters']);
        $this->assertSame('stress', $context['characters'][0]['profile']['runtimeStatus'][0]['status_key']);
        $this->assertSame(35.0, $context['characters'][0]['profile']['runtimeStatus'][0]['status_value']);
    }

    public function test_scene_context_builder_includes_active_quests_for_continuity(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_runtime', 'Vault Runtime'));
        (new EloquentSceneRepository())->save(Activity::fromArray([
            'id' => 'scene_runtime',
            'vaultId' => 'vault_runtime',
            'title' => 'Escena runtime',
            'draft' => 'Borrador runtime',
        ]));

        Continuity::query()->create([
            'id' => 'cont_runtime',
            'parent_id' => null,
            'root_id' => 'cont_runtime',
            'label' => 'Runtime',
            'status' => 'active',
        ]);
        SceneActiveContinuity::query()->create([
            'activity_id' => 'scene_runtime',
            'continuity_id' => 'cont_runtime',
            'continuity_commit_id' => null,
        ]);

        Quest::query()->create([
            'id' => 'quest_escape',
            'vault_id' => 'vault_runtime',
            'title' => 'Fuga del refugio',
            'description' => 'Salir del refugio',
            'type' => 'main',
            'status' => 'active',
        ]);
        QuestStep::query()->create([
            'quest_id' => 'quest_escape',
            'stage_number' => 20,
            'description' => 'Neutraliza al guardia de la puerta',
            'is_optional' => false,
        ]);
        ContinuityQuestStatus::query()->create([
            'continuity_id' => 'cont_runtime',
            'activity_id' => 'scene_runtime',
            'quest_id' => 'quest_escape',
            'status' => 'active',
            'current_stage_number' => 20,
            'ai_summary' => 'El guardia sigue bloqueando la salida.',
        ]);

        $context = (new EloquentSceneContextBuilder(
            new EloquentCharacterRuntimeStatusRepository(),
            new EloquentContinuityQuestStatusRepository(),
        ))->build('scene_runtime', 'cont_runtime');

        $this->assertCount(1, $context['quests']);
        $this->assertSame('quest_escape', $context['quests'][0]['quest_id']);
        $this->assertSame(20, $context['quests'][0]['current_stage_number']);
        $this->assertSame('Neutraliza al guardia de la puerta', $context['quests'][0]['current_step']['description']);
    }
}
