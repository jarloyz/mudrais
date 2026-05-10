<?php

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\ApplyQuestProgressDirectiveUseCase;
use App\Models\Continuity;
use App\Models\ContinuityQuestStatus;
use App\Models\Quest;
use App\Models\QuestStep;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class ApplyQuestProgressDirectiveUseCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_invalid_stage_jump(): void
    {
        $this->seedQuestFixture();

        $result = $this->makeUseCase()->execute('cont_quest', 'scene_quest', [
            'matched' => true,
            'quest_id' => 'quest_escape',
            'advance_step' => true,
            'new_stage_number' => 40,
            'new_status' => 'active',
            'ai_summary' => 'Salta demasiadas etapas.',
        ]);

        $this->assertFalse($result['applied']);
        $this->assertSame('invalid_stage_jump', $result['reason']);
        $this->assertDatabaseHas('continuity_quest_statuses', [
            'continuity_id' => 'cont_quest',
            'quest_id' => 'quest_escape',
            'current_stage_number' => 20,
        ]);
    }

    public function test_normalizes_completed_without_stage_to_last_stage(): void
    {
        $this->seedQuestFixture();

        $result = $this->makeUseCase()->execute('cont_quest', 'scene_quest', [
            'matched' => true,
            'quest_id' => 'quest_escape',
            'advance_step' => false,
            'new_stage_number' => null,
            'new_status' => 'completed',
            'ai_summary' => 'La fuga queda cerrada.',
        ]);

        $this->assertTrue($result['applied']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(40, $result['currentStageNumber']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_preserves_current_stage_when_marking_failed(): void
    {
        $this->seedQuestFixture();

        $result = $this->makeUseCase()->execute('cont_quest', 'scene_quest', [
            'matched' => true,
            'quest_id' => 'quest_escape',
            'advance_step' => false,
            'new_stage_number' => null,
            'new_status' => 'failed',
            'ai_summary' => 'La fuga se arruina.',
        ]);

        $this->assertTrue($result['applied']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(20, $result['currentStageNumber']);
    }

    private function makeUseCase(): ApplyQuestProgressDirectiveUseCase
    {
        return new ApplyQuestProgressDirectiveUseCase(
            app(\App\Application\Contracts\ContinuityQuestStatusRepository::class),
            new ArrayStructuredLogger(),
        );
    }

    private function seedQuestFixture(): void
    {
        Vault::query()->create([
            'id' => 'vault_quest',
            'name' => 'Vault Quest',
            'status' => 'active',
        ]);
        Continuity::query()->create([
            'id' => 'cont_quest',
            'parent_id' => null,
            'root_id' => 'cont_quest',
            'label' => 'Quest',
            'status' => 'active',
        ]);
        Quest::query()->create([
            'id' => 'quest_escape',
            'vault_id' => 'vault_quest',
            'title' => 'Fuga del refugio',
            'description' => 'Escapar',
            'type' => 'main',
            'status' => 'active',
        ]);
        DB::table('activities')->insert([
            'id' => 'scene_quest',
            'vault_id' => 'vault_quest',
            'title' => 'Escena quest',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => 'draft',
            'draft' => 'Base',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        QuestStep::query()->create([
            'quest_id' => 'quest_escape',
            'stage_number' => 20,
            'description' => 'Neutraliza al guardia',
            'is_optional' => false,
        ]);
        QuestStep::query()->create([
            'quest_id' => 'quest_escape',
            'stage_number' => 30,
            'description' => 'Abre la ruta',
            'is_optional' => false,
        ]);
        QuestStep::query()->create([
            'quest_id' => 'quest_escape',
            'stage_number' => 40,
            'description' => 'Escapa',
            'is_optional' => false,
        ]);
        ContinuityQuestStatus::query()->create([
            'continuity_id' => 'cont_quest',
            'scene_id' => 'scene_quest',
            'quest_id' => 'quest_escape',
            'status' => 'active',
            'current_stage_number' => 20,
            'ai_summary' => 'El guardia aun bloquea la salida.',
        ]);
    }
}
