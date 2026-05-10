<?php

namespace Tests\Unit\Application\Services;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Application\Services\TagNormalizerService;
use App\Models\CanonicalTag;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class TagNormalizerServiceTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    private EmbeddingGateway $embedding;
    private AiChatGateway $ai;
    private QdrantService $qdrant;
    private UserAiSettingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();

        $this->embedding = Mockery::mock(EmbeddingGateway::class);
        $this->ai        = Mockery::mock(AiChatGateway::class);
        $this->qdrant    = Mockery::mock(QdrantService::class);
        $this->resolver  = Mockery::mock(UserAiSettingsResolver::class);

        $this->resolver->shouldReceive('resolveAgentModel')
            ->with(Mockery::any(), 'embedding')
            ->andReturn('text-embedding-model')
            ->byDefault();

        $this->resolver->shouldReceive('resolveAgentProvider')
            ->andReturn(null)
            ->byDefault();

        $this->qdrant->shouldReceive('ensureTaxonomyCollection')->byDefault();
    }

    private function makeService(): TagNormalizerService
    {
        return new TagNormalizerService(
            $this->embedding,
            $this->ai,
            $this->qdrant,
            $this->resolver,
        );
    }

    public function test_sql_exact_match_returns_without_any_api_call(): void
    {
        $tag = CanonicalTag::create(['slug' => 'epic_fantasy', 'name' => 'Epic Fantasy', 'is_active' => true]);

        $this->embedding->shouldNotReceive('embed');
        $this->qdrant->shouldNotReceive('searchTaxonomyTags');

        $result = $this->makeService()->normalizeTag('epic fantasy');

        $this->assertEquals($tag->id, $result?->id);
    }

    public function test_green_match_on_first_search_returns_without_llm(): void
    {
        $tag = CanonicalTag::create(['slug' => 'grimdark', 'name' => 'Grimdark', 'is_active' => true]);

        $this->embedding->shouldReceive('embed')->once()->andReturn([0.1, 0.2]);
        $this->qdrant->shouldReceive('searchTaxonomyTags')->once()->andReturn([
            ['score' => 0.95, 'payload' => ['canonical_tag_id' => $tag->id, 'slug' => 'grimdark', 'name' => 'Grimdark']],
        ]);
        $this->ai->shouldNotReceive('chat');

        $result = $this->makeService()->normalizeTag('dark gritty tone');

        $this->assertEquals($tag->id, $result?->id);
    }

    public function test_yellow_match_confirmed_by_gatekeeper_returns_existing_tag(): void
    {
        $tag = CanonicalTag::create(['slug' => 'mystery', 'name' => 'Mystery', 'is_active' => true]);

        $this->embedding->shouldReceive('embed')->once()->andReturn([0.1, 0.2]);
        $this->qdrant->shouldReceive('searchTaxonomyTags')->once()->andReturn([
            ['score' => 0.72, 'payload' => ['canonical_tag_id' => $tag->id, 'slug' => 'mystery', 'name' => 'Mystery']],
        ]);

        $this->resolver->shouldReceive('resolveAgentModel')->with(Mockery::any(), 'gatekeeper')->andReturn('gatekeeper-model');
        $this->ai->shouldReceive('chat')->once()->andReturn(['text' => 'mystery']);
        $this->ai->shouldNotReceive('chat'); // optimizer_fast no debe llamarse

        $result = $this->makeService()->normalizeTag('whodunit investigation');

        $this->assertEquals($tag->id, $result?->id);
    }

    public function test_second_search_green_match_avoids_duplicate_tag(): void
    {
        $tag = CanonicalTag::create(['slug' => 'political_intrigue', 'name' => 'Political Intrigue', 'is_active' => true]);

        $this->embedding->shouldReceive('embed')
            ->twice() // rawText embed + enriched embed
            ->andReturn([0.1, 0.2], [0.3, 0.4]);

        // Primera búsqueda → sin match
        // Segunda búsqueda (enriched) → 🟢 match
        $this->qdrant->shouldReceive('searchTaxonomyTags')
            ->twice()
            ->andReturn(
                [['score' => 0.40, 'payload' => ['canonical_tag_id' => $tag->id, 'slug' => 'political_intrigue', 'name' => 'Political Intrigue']]],
                [['score' => 0.92, 'payload' => ['canonical_tag_id' => $tag->id, 'slug' => 'political_intrigue', 'name' => 'Political Intrigue']]],
            );

        $this->resolver->shouldReceive('resolveAgentModel')->with(Mockery::any(), 'optimizer_fast')->andReturn('fast-model');
        $this->ai->shouldReceive('chat')->once()->andReturn(['text' => '{"slug":"political_intrigue","name":"Political Intrigue","description":"Court schemes and power struggles"}']);

        $this->qdrant->shouldNotReceive('insertTaxonomyTag'); // no debe crear

        $result = $this->makeService()->normalizeTag('court schemes and power');

        $this->assertEquals($tag->id, $result?->id);
    }

    public function test_second_search_yellow_match_confirmed_by_gatekeeper(): void
    {
        $tag = CanonicalTag::create(['slug' => 'heist', 'name' => 'Heist', 'is_active' => true]);

        $this->embedding->shouldReceive('embed')
            ->twice()
            ->andReturn([0.1, 0.2], [0.3, 0.4]);

        $this->qdrant->shouldReceive('searchTaxonomyTags')
            ->twice()
            ->andReturn(
                [['score' => 0.35, 'payload' => ['canonical_tag_id' => $tag->id, 'slug' => 'heist', 'name' => 'Heist']]],
                [['score' => 0.65, 'payload' => ['canonical_tag_id' => $tag->id, 'slug' => 'heist', 'name' => 'Heist']]],
            );

        $this->resolver->shouldReceive('resolveAgentModel')->with(Mockery::any(), 'optimizer_fast')->andReturn('fast-model');
        $this->resolver->shouldReceive('resolveAgentModel')->with(Mockery::any(), 'gatekeeper')->andReturn('gatekeeper-model');

        // optimizer_fast para enriquecer, gatekeeper para verificar segunda búsqueda
        $this->ai->shouldReceive('chat')
            ->twice()
            ->andReturn(
                ['text' => '{"slug":"heist","name":"Heist","description":"Theft planning and execution"}'],
                ['text' => 'heist'],
            );

        $this->qdrant->shouldNotReceive('insertTaxonomyTag');

        $result = $this->makeService()->normalizeTag('elaborate robbery planning');

        $this->assertEquals($tag->id, $result?->id);
    }

    public function test_creates_new_tag_when_both_searches_fail(): void
    {
        $this->embedding->shouldReceive('embed')
            ->twice()
            ->andReturn([0.1, 0.2], [0.3, 0.4]);

        $this->qdrant->shouldReceive('searchTaxonomyTags')
            ->twice()
            ->andReturn(
                [],
                [],
            );

        $this->resolver->shouldReceive('resolveAgentModel')->with(Mockery::any(), 'optimizer_fast')->andReturn('fast-model');
        $this->ai->shouldReceive('chat')->once()->andReturn(['text' => '{"slug":"solarpunk_utopia","name":"Solarpunk Utopia","description":"Optimistic eco-tech future"}']);

        $this->qdrant->shouldReceive('insertTaxonomyTag')->once();

        $result = $this->makeService()->normalizeTag('green tech optimistic future');

        $this->assertNotNull($result);
        $this->assertEquals('solarpunk_utopia', $result->slug);
        $this->assertTrue((bool) $result->is_active);
    }

    public function test_does_not_duplicate_tag_when_slug_already_exists_in_db(): void
    {
        $existing = CanonicalTag::create(['slug' => 'noir', 'name' => 'Noir', 'is_active' => true]);

        $this->embedding->shouldReceive('embed')->twice()->andReturn([0.1, 0.2], [0.3, 0.4]);
        $this->qdrant->shouldReceive('searchTaxonomyTags')->twice()->andReturn([], []);

        $this->resolver->shouldReceive('resolveAgentModel')->with(Mockery::any(), 'optimizer_fast')->andReturn('fast-model');
        $this->ai->shouldReceive('chat')->once()->andReturn(['text' => '{"slug":"noir","name":"Noir","description":"Dark crime fiction"}']);

        $this->qdrant->shouldReceive('insertTaxonomyTag')->once();

        $result = $this->makeService()->normalizeTag('dark detective crime');

        $this->assertEquals($existing->id, $result?->id);
        $this->assertEquals(1, CanonicalTag::where('slug', 'noir')->count());
    }
}
