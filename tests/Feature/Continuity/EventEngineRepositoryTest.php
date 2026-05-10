<?php

namespace Tests\Feature\Continuity;

use App\Infrastructure\Persistence\Eloquent\EloquentEventEngineRepository;
use App\Models\Continuity;
use App\Models\ContinuityQuestStatus;
use App\Models\ContinuityStateChange;
use App\Models\Event;
use App\Models\EventCondition;
use App\Models\EventEffect;
use App\Models\EventRun;
use App\Models\Quest;
use App\Models\Vault;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class EventEngineRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluate_and_apply_triggers_fires_event_and_applies_effect(): void
    {
        $this->seedBaseFixture();

        Event::query()->create([
            'id' => 'event_alarm',
            'title' => 'Alarma emocional',
            'activity_id' => 'scene_event',
            'importance' => 3,
            'status' => 'active',
            'cooldown_turns' => 2,
        ]);
        EventCondition::query()->create([
            'event_id' => 'event_alarm',
            'continuity_id' => null,
            'scope_type' => 'state',
            'operator' => 'contains',
            'value_text' => 'stress',
            'weight' => 20,
            'required' => true,
            'sort_order' => 1,
            'active' => true,
        ]);
        EventEffect::query()->create([
            'event_id' => 'event_alarm',
            'continuity_id' => null,
            'effect_type' => 'state_change',
            'kind' => 'state',
            'scope_type' => 'scene',
            'scope_id' => null,
            'change_text' => 'La tension del entorno aumenta',
            'severity' => 3,
            'sort_order' => 1,
            'active' => true,
        ]);

        ContinuityStateChange::query()->create([
            'continuity_id' => 'cont_event',
            'activity_id' => 'scene_event',
            'kind' => 'state',
            'scope_type' => 'character',
            'scope_id' => 'ana',
            'change' => 'stress: 33',
            'severity' => 2,
        ]);

        $result = (new EloquentEventEngineRepository())->evaluateAndApplyTriggers([
            'continuityId' => 'cont_event',
            'sceneId' => 'scene_event',
            'locationId' => '',
            'turnIndex' => 1,
            'characterIds' => ['ana'],
            'tags' => [],
            'maxCandidates' => 10,
            'maxFired' => 3,
            'minScore' => 10,
        ]);

        $this->assertSame(1, $result['firedCount']);
        $this->assertSame(1, $result['effectsApplied']);
        $this->assertDatabaseHas('continuity_state_changes', [
            'continuity_id' => 'cont_event',
            'activity_id' => 'scene_event',
            'change' => 'La tension del entorno aumenta',
        ]);
        $this->assertDatabaseHas('event_runs', [
            'event_id' => 'event_alarm',
            'continuity_id' => 'cont_event',
            'activity_id' => 'scene_event',
            'turn_index' => 1,
            'fired' => 1,
        ]);
        $this->assertSame(1, Event::query()->find('event_alarm')?->last_fired_turn);
    }

    public function test_evaluate_and_apply_triggers_respects_cooldown(): void
    {
        $this->seedBaseFixture();

        Event::query()->create([
            'id' => 'event_alarm',
            'title' => 'Alarma emocional',
            'activity_id' => 'scene_event',
            'importance' => 3,
            'status' => 'active',
            'cooldown_turns' => 3,
            'last_fired_turn' => 1,
        ]);
        EventCondition::query()->create([
            'event_id' => 'event_alarm',
            'continuity_id' => null,
            'scope_type' => 'scene',
            'operator' => 'eq',
            'value_text' => 'scene_event',
            'weight' => 20,
            'required' => true,
            'sort_order' => 1,
            'active' => true,
        ]);

        $result = (new EloquentEventEngineRepository())->evaluateAndApplyTriggers([
            'continuityId' => 'cont_event',
            'sceneId' => 'scene_event',
            'locationId' => '',
            'turnIndex' => 2,
            'characterIds' => ['ana'],
            'tags' => [],
            'maxCandidates' => 10,
            'maxFired' => 3,
            'minScore' => 10,
        ]);

        $this->assertSame(0, $result['firedCount']);
        $this->assertDatabaseHas('event_runs', [
            'event_id' => 'event_alarm',
            'continuity_id' => 'cont_event',
            'activity_id' => 'scene_event',
            'turn_index' => 2,
            'fired' => 0,
        ]);
    }

    public function test_evaluate_and_apply_triggers_supports_quest_stage_conditions(): void
    {
        $this->seedBaseFixture();

        Quest::query()->create([
            'id' => 'quest_escape',
            'vault_id' => 'vault_event',
            'title' => 'Fuga del refugio',
            'description' => 'Escapa del refugio',
            'type' => 'main',
            'status' => 'active',
        ]);
        ContinuityQuestStatus::query()->create([
            'continuity_id' => 'cont_event',
            'activity_id' => 'scene_event',
            'quest_id' => 'quest_escape',
            'status' => 'active',
            'current_stage_number' => 30,
            'ai_summary' => 'La salida ya esta libre.',
        ]);

        Event::query()->create([
            'id' => 'event_escape_open',
            'title' => 'Ruta desbloqueada',
            'activity_id' => 'scene_event',
            'quest_id' => 'quest_escape',
            'importance' => 2,
            'status' => 'active',
            'cooldown_turns' => 0,
        ]);
        EventCondition::query()->create([
            'event_id' => 'event_escape_open',
            'scope_type' => 'quest',
            'operator' => 'eq',
            'value_text' => '30',
            'weight' => 15,
            'required' => true,
            'sort_order' => 1,
            'active' => true,
        ]);
        EventEffect::query()->create([
            'event_id' => 'event_escape_open',
            'effect_type' => 'state_change',
            'kind' => 'state',
            'scope_type' => 'scene',
            'change_text' => 'La ruta de escape queda desbloqueada',
            'severity' => 2,
            'sort_order' => 1,
            'active' => true,
        ]);

        $entries = [];
        $result = (new EloquentEventEngineRepository(new ArrayStructuredLogger($entries)))->evaluateAndApplyTriggers([
            'continuityId' => 'cont_event',
            'sceneId' => 'scene_event',
            'locationId' => '',
            'turnIndex' => 3,
            'characterIds' => [],
            'tags' => [],
            'maxCandidates' => 10,
            'maxFired' => 3,
            'minScore' => 10,
        ]);

        $this->assertSame(1, $result['firedCount']);
        $this->assertDatabaseHas('event_runs', [
            'event_id' => 'event_escape_open',
            'continuity_id' => 'cont_event',
            'activity_id' => 'scene_event',
            'turn_index' => 3,
            'fired' => 1,
        ]);
        $this->assertDatabaseHas('continuity_state_changes', [
            'continuity_id' => 'cont_event',
            'activity_id' => 'scene_event',
            'change' => 'La ruta de escape queda desbloqueada',
        ]);
        $this->assertSame('Inicio de evaluacion de eventos', $entries[0]['message']);
        $this->assertSame('Evaluacion de eventos completada', $entries[array_key_last($entries)]['message']);
        $this->assertSame('cont_event', $entries[0]['context']['continuityId']);
    }

    private function seedBaseFixture(): void
    {
        Vault::query()->create([
            'id' => 'vault_event',
            'name' => 'Vault Event',
            'status' => 'active',
        ]);
        DB::table('activities')->insert([
            'id' => 'scene_event',
            'vault_id' => 'vault_event',
            'title' => 'Escena evento',
            'chapter' => 1,
            'scene_number' => 1,
            'status' => 'draft',
            'draft' => 'base',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Continuity::query()->create([
            'id' => 'cont_event',
            'parent_id' => null,
            'root_id' => 'cont_event',
            'label' => 'Continuidad Event',
            'status' => 'active',
        ]);
    }
}
