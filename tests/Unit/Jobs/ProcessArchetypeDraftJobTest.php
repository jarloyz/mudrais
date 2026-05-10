<?php

namespace Tests\Unit\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use App\Domains\Matchmaking\Models\ArchetypeDraft;
use App\Infrastructure\Ai\Agents\ArchetypeOptimizerAgent;
use App\Jobs\ProcessArchetypeDraftJob;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessArchetypeDraftJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeSettingsResolver(): UserAiSettingsResolver
    {
        $mock = $this->createMock(UserAiSettingsResolver::class);
        $mock->method('resolveAgentModel')->willReturn('nomic-ai/nomic-embed-text-v1.5');
        return $mock;
    }

    private function makeOptimizerResult(array $overrides = []): array
    {
        return array_merge([
            'name_es'            => 'Nombre',
            'name_en'            => 'Name',
            'optimized_text_en'  => 'Optimized',
            'semantic_tag_query' => 'test query for taxonomy tags',
        ], $overrides);
    }

    public function test_transitions_draft_to_needs_review_on_success(): void
    {
        $draft = ArchetypeDraft::create([
            'input_name' => 'Raw Name',
            'input_text' => 'Raw Text',
            'status'     => ArchetypeDraftStatus::PENDING->value,
        ]);

        $optimizerAgent = $this->createMock(ArchetypeOptimizerAgent::class);
        $optimizerAgent->expects($this->once())
            ->method('optimize')
            ->willReturn($this->makeOptimizerResult());

        $embeddingGateway = $this->createMock(EmbeddingGateway::class);
        $embeddingGateway->expects($this->exactly(2))
            ->method('embed')
            ->willReturn([0.1, 0.2, 0.3]);

        $qdrantService = $this->createMock(QdrantService::class);
        $qdrantService->expects($this->once())
            ->method('searchTaxonomyTags')
            ->willReturn([]);

        $job = new ProcessArchetypeDraftJob($draft->id);
        $job->handle($optimizerAgent, $embeddingGateway, $qdrantService, $this->makeSettingsResolver());

        $draft->refresh();

        $this->assertEquals(ArchetypeDraftStatus::NEEDS_REVIEW, $draft->status);
        $this->assertEquals('Nombre', $draft->name_es);
        $this->assertEquals('Name', $draft->name_en);
        $this->assertEquals('name', $draft->slug);
        $this->assertEquals('Optimized', $draft->optimized_text_en);
        $this->assertEquals('test query for taxonomy tags', $draft->semantic_tag_query);
        $this->assertEquals([0.1, 0.2, 0.3], $draft->style_vector);
        $this->assertIsArray($draft->suggested_tags);
    }

    public function test_transitions_draft_to_error_on_agent_exception(): void
    {
        $draft = ArchetypeDraft::create([
            'input_name' => 'Raw Name',
            'input_text' => 'Raw Text',
            'status'     => ArchetypeDraftStatus::PENDING->value,
        ]);

        $optimizerAgent = $this->createMock(ArchetypeOptimizerAgent::class);
        $optimizerAgent->expects($this->once())
            ->method('optimize')
            ->willThrowException(new \RuntimeException('AI Error'));

        $embeddingGateway = $this->createMock(EmbeddingGateway::class);
        $embeddingGateway->expects($this->never())->method('embed');

        $qdrantService = $this->createMock(QdrantService::class);

        $job = new ProcessArchetypeDraftJob($draft->id);
        $job->handle($optimizerAgent, $embeddingGateway, $qdrantService, $this->makeSettingsResolver());

        $draft->refresh();

        $this->assertEquals(ArchetypeDraftStatus::ERROR, $draft->status);
        $this->assertEquals('AI Error', $draft->processing_error);
    }

    public function test_skips_if_draft_not_found(): void
    {
        $optimizerAgent = $this->createMock(ArchetypeOptimizerAgent::class);
        $optimizerAgent->expects($this->never())->method('optimize');

        $job = new ProcessArchetypeDraftJob(999);
        $job->handle(
            $optimizerAgent,
            $this->createMock(EmbeddingGateway::class),
            $this->createMock(QdrantService::class),
            $this->makeSettingsResolver()
        );
        $this->assertTrue(true);
    }

    public function test_skips_if_draft_not_in_pending_status(): void
    {
        $draft = ArchetypeDraft::create([
            'input_name' => 'Raw Name',
            'input_text' => 'Raw Text',
            'status'     => ArchetypeDraftStatus::APPROVED->value,
        ]);

        $optimizerAgent = $this->createMock(ArchetypeOptimizerAgent::class);
        $optimizerAgent->expects($this->never())->method('optimize');

        $job = new ProcessArchetypeDraftJob($draft->id);
        $job->handle(
            $optimizerAgent,
            $this->createMock(EmbeddingGateway::class),
            $this->createMock(QdrantService::class),
            $this->makeSettingsResolver()
        );
        $this->assertTrue(true);
    }

    public function test_calls_search_taxonomy_tags_with_threshold_082(): void
    {
        $draft = ArchetypeDraft::create([
            'input_name' => 'Raw Name',
            'input_text' => 'Raw Text',
            'status'     => ArchetypeDraftStatus::PENDING->value,
        ]);

        $optimizerAgent = $this->createMock(ArchetypeOptimizerAgent::class);
        $optimizerAgent->method('optimize')->willReturn($this->makeOptimizerResult([
            'name_es' => 'E', 'name_en' => 'E', 'optimized_text_en' => 'O',
        ]));

        $embeddingGateway = $this->createMock(EmbeddingGateway::class);
        $embeddingGateway->method('embed')->willReturn([0.1]);

        $qdrantService = $this->createMock(QdrantService::class);
        $qdrantService->expects($this->once())
            ->method('searchTaxonomyTags')
            ->with([0.1], 5, 0.82)
            ->willReturn([]);

        $job = new ProcessArchetypeDraftJob($draft->id);
        $job->handle($optimizerAgent, $embeddingGateway, $qdrantService, $this->makeSettingsResolver());
    }

    public function test_suggested_tags_have_correct_structure(): void
    {
        $draft = ArchetypeDraft::create([
            'input_name' => 'Raw Name',
            'input_text' => 'Raw Text',
            'status'     => ArchetypeDraftStatus::PENDING->value,
        ]);

        $optimizerAgent = $this->createMock(ArchetypeOptimizerAgent::class);
        $optimizerAgent->method('optimize')->willReturn($this->makeOptimizerResult([
            'name_es' => 'E', 'name_en' => 'E', 'optimized_text_en' => 'O',
        ]));

        $embeddingGateway = $this->createMock(EmbeddingGateway::class);
        $embeddingGateway->method('embed')->willReturn([0.1]);

        $qdrantService = $this->createMock(QdrantService::class);
        $qdrantService->method('searchTaxonomyTags')->willReturn([
            [
                'score'   => 0.85,
                'payload' => [
                    'canonical_tag_id' => 12,
                    'slug'             => 'test_tag',
                    'name'             => 'Test Tag',
                ],
            ],
        ]);

        $job = new ProcessArchetypeDraftJob($draft->id);
        $job->handle($optimizerAgent, $embeddingGateway, $qdrantService, $this->makeSettingsResolver());

        $draft->refresh();
        $this->assertIsArray($draft->suggested_tags);
        $this->assertCount(1, $draft->suggested_tags);
        $this->assertEquals('qdrant', $draft->suggested_tags[0]['source']);
        $this->assertEquals(12, $draft->suggested_tags[0]['canonical_tag_id']);
        $this->assertEquals('test_tag', $draft->suggested_tags[0]['slug']);
        $this->assertEquals('Test Tag', $draft->suggested_tags[0]['name']);
        $this->assertEquals(0.85, $draft->suggested_tags[0]['score']);
    }
}
