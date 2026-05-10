<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\OptimizerProfileAgent;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypePrompt;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class OptimizerProfileAgentTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    private AiChatGateway $gateway;
    private UserAiSettingsResolver $resolver;
    private OptimizerProfileAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();

        $this->gateway  = Mockery::mock(AiChatGateway::class);
        $this->resolver = Mockery::mock(UserAiSettingsResolver::class);
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null)->byDefault();
        $this->agent    = new OptimizerProfileAgent($this->gateway, $this->resolver);
    }

    public function test_optimize_uses_db_template_when_no_archetype(): void
    {
        $this->resolver->shouldReceive('resolveAgentModel')->with(null, 'optimizer')->andReturn('some-model');

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($model, $messages, $temp, $tokens) => $model === 'some-model' && $temp === 0.1 && $tokens === 600)
            ->andReturn(['text' => 'A player who loves sci-fi and action.']);

        $result = $this->agent->optimize(['Sci-Fi', 'Action'], null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('optimized_text', $result);
        $this->assertEquals('A player who loves sci-fi and action.', $result['optimized_text']);
    }

    public function test_optimize_uses_legacy_archetype_prompt_when_provided(): void
    {
        $archetype = Archetype::create([
            'name'               => 'TTRPG Texto',
            'qdrant_vector_name' => 'ttrpg_text_v1',
        ]);

        ArchetypePrompt::create([
            'archetype_id'  => $archetype->id,
            'agent_type'    => 'optimizer',
            'system_prompt' => 'Custom optimizer prompt for TTRPG.',
        ]);

        $archetype->load('prompts');

        $this->resolver->shouldReceive('resolveAgentModel')->with(null, 'optimizer')->andReturn('some-model');

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                return $messages[0]['content'] === 'Custom optimizer prompt for TTRPG.';
            })
            ->andReturn(['text' => 'Dense semantic paragraph.']);

        $result = $this->agent->optimize(['Fantasy', 'Romance'], $archetype);

        $this->assertIsArray($result);
        $this->assertEquals('Dense semantic paragraph.', $result['optimized_text']);
    }

    public function test_optimize_returns_empty_result_for_empty_prefs(): void
    {
        $result = $this->agent->optimize([]);

        $this->assertIsArray($result);
        $this->assertEquals('', $result['optimized_text']);
        $this->assertEquals('', $result['semantic_tag_query']);
    }

    public function test_optimize_throws_when_model_not_configured(): void
    {
        $this->resolver->shouldReceive('resolveAgentModel')->with(null, 'optimizer')->andReturn('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modelo optimizer no configurado.');

        $this->agent->optimize(['Sci-Fi']);
    }

    public function test_optimize_throws_when_response_is_empty(): void
    {
        $this->resolver->shouldReceive('resolveAgentModel')->andReturn('some-model');
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null);
        $this->gateway->shouldReceive('chat')->andReturn(['text' => '   ']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OptimizerProfileAgent devolvió respuesta vacía.');

        $this->agent->optimize(['Horror']);
    }
}
