<?php

namespace Tests\Feature\ArchetypeDraft;

use App\Application\Contracts\AiChatGateway;
use App\Application\Services\TagNormalizerService;
use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeDraft;
use App\Filament\Resources\ArchetypeDrafts\Pages\CreateArchetypeDraft;
use App\Filament\Resources\ArchetypeDrafts\Pages\ViewArchetypeDraft;
use App\Jobs\GenerateArchetypeTagProposalsJob;
use App\Jobs\IndexArchetypeJob;
use App\Jobs\ProcessArchetypeDraftJob;
use App\Models\CanonicalTag;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ArchetypeDraftPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_draft_dispatches_process_job(): void
    {
        Queue::fake();

        $admin = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(CreateArchetypeDraft::class)
            ->fillForm([
                'input_name' => 'Test Name',
                'input_text' => 'Test Text',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Queue::assertPushed(ProcessArchetypeDraftJob::class, function ($job) {
            $draft = ArchetypeDraft::latest()->first();
            return $job->draftId === $draft->id;
        });

        $draft = ArchetypeDraft::latest()->first();
        $this->assertEquals('Test Name', $draft->input_name);
        $this->assertEquals(ArchetypeDraftStatus::PENDING, $draft->status);
    }

    public function test_approve_creates_archetype_and_syncs_qdrant_tags(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $tag = CanonicalTag::create(['slug' => 'test', 'name' => 'Test', 'is_active' => true]);

        $draft = ArchetypeDraft::create([
            'input_name' => 'Input',
            'input_text' => 'Text',
            'name_es' => 'Prueba',
            'name_en' => 'Test',
            'slug' => 'test',
            'optimized_text_en' => 'Optimized text',
            'status' => ArchetypeDraftStatus::NEEDS_REVIEW->value,
            'suggested_tags' => [
                [
                    'source' => 'qdrant',
                    'canonical_tag_id' => $tag->id,
                    'slug' => 'test',
                    'name' => 'Test',
                    'score' => 0.9,
                ]
            ]
        ]);

        Livewire::actingAs($admin)
            ->test(ViewArchetypeDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction('aprobar')
            ->assertHasNoActionErrors();

        $draft->refresh();
        $this->assertEquals(ArchetypeDraftStatus::APPROVED, $draft->status);
        $this->assertNotNull($draft->archetype_id);

        $archetype = Archetype::find($draft->archetype_id);
        $this->assertEquals('Test', $archetype->name);
        $this->assertEquals('test', $archetype->slug);

        $this->assertCount(1, $archetype->tags);
        $this->assertEquals($tag->id, $archetype->tags->first()->id);

        Queue::assertPushed(IndexArchetypeJob::class);
    }

    public function test_approve_creates_new_canonical_tag_for_ai_proposals(): void
    {
        Queue::fake();

        $this->mock(TagNormalizerService::class, function ($mock) {
            $mock->shouldReceive('indexExistingTag')->once();
        });

        $admin = User::factory()->create();

        $draft = ArchetypeDraft::create([
            'input_name' => 'Input',
            'input_text' => 'Text',
            'name_es' => 'Prueba',
            'name_en' => 'Test',
            'slug' => 'test',
            'optimized_text_en' => 'Optimized text',
            'status' => ArchetypeDraftStatus::NEEDS_REVIEW->value,
            'suggested_tags' => [
                [
                    'source' => 'ai_proposal',
                    'slug' => 'new_tag_ai',
                    'name' => 'New Tag AI',
                    'description' => 'A new tag.',
                ]
            ]
        ]);

        Livewire::actingAs($admin)
            ->test(ViewArchetypeDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction('aprobar')
            ->assertHasNoActionErrors();

        $draft->refresh();
        $this->assertEquals(ArchetypeDraftStatus::APPROVED, $draft->status);

        $tag = CanonicalTag::where('slug', 'new_tag_ai')->first();
        $this->assertNotNull($tag);
        $this->assertEquals('New Tag AI', $tag->name);

        $archetype = Archetype::find($draft->archetype_id);
        $this->assertTrue($archetype->tags->contains($tag->id));
    }

    public function test_generate_proposals_button_dispatches_job(): void
    {
        Queue::fake();
        $admin = User::factory()->create();

        $draft = ArchetypeDraft::create([
            'input_name' => 'Input',
            'input_text' => 'Text',
            'status' => ArchetypeDraftStatus::NEEDS_REVIEW->value,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewArchetypeDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction('proponer_tags');

        Queue::assertPushed(GenerateArchetypeTagProposalsJob::class);
    }

    public function test_reject_transitions_status_to_rejected(): void
    {
        $admin = User::factory()->create();

        $draft = ArchetypeDraft::create([
            'input_name' => 'Input',
            'input_text' => 'Text',
            'status' => ArchetypeDraftStatus::NEEDS_REVIEW->value,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewArchetypeDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction('rechazar', data: ['note' => 'No me gusta.']);

        $draft->refresh();
        $this->assertEquals(ArchetypeDraftStatus::REJECTED, $draft->status);
        $this->assertEquals('No me gusta.', $draft->processing_error);
    }
}
