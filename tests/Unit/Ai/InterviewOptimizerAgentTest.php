<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypePrompt;
use App\Infrastructure\Ai\Agents\InterviewOptimizerAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class InterviewOptimizerAgentTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    private AiChatGateway $gateway;
    private UserAiSettingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();

        $this->gateway  = Mockery::mock(AiChatGateway::class);
        $this->resolver = Mockery::mock(UserAiSettingsResolver::class);

        $this->resolver->shouldReceive('resolveAgentModel')->andReturn('test-model')->byDefault();
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null)->byDefault();
    }

    public function test_optimize_returns_normalized_fields_on_valid_json(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => json_encode([
                'optimized_fields' => [
                    'preferences' => 'sci-fi, space opera, cyberpunk',
                    'style'       => 'action-oriented, fast-paced narrative',
                ],
            ])]);

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize([
            'preferences' => 'sci fi stuff',
            'style'       => 'fast action',
        ]);

        $this->assertArrayHasKey('preferences', $result);
        $this->assertArrayHasKey('style', $result);
        $this->assertEquals('sci-fi, space opera, cyberpunk', $result['preferences']);
    }

    public function test_optimize_skips_llm_when_all_fields_trivial(): void
    {
        // Fields with len < 3 should be filtered out; if nothing left, skip LLM call
        $this->gateway->shouldReceive('chat')->never();

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize(['preferences' => 'ok', 'style' => 'a']);

        $this->assertEmpty($result);
    }

    public function test_optimize_returns_empty_on_invalid_json(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => 'not json {broken}']);

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize(['preferences' => 'valid value here']);

        $this->assertEmpty($result);
    }

    public function test_optimize_uses_interview_optimizer_archetype_prompt_when_set(): void
    {
        // 'interview_optimizer' archetype prompt is used (not 'optimizer' which returns plain text)
        $archetype = Archetype::create([
            'name'               => 'TTRPG Fantasy',
            'qdrant_vector_name' => 'fantasy_v1',
        ]);

        ArchetypePrompt::create([
            'archetype_id'  => $archetype->id,
            'agent_type'    => 'interview_optimizer',
            'system_prompt' => 'Custom interview_optimizer for fantasy archetype.',
        ]);

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                return $messages[0]['content'] === 'Custom interview_optimizer for fantasy archetype.';
            })
            ->andReturn(['text' => json_encode(['optimized_fields' => ['preferences' => 'epic fantasy lore']])]);

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize(['preferences' => 'fantasy stuff'], $archetype);

        $this->assertEquals('epic fantasy lore', $result['preferences']);
    }

    public function test_optimize_does_not_use_plain_text_optimizer_archetype_prompt(): void
    {
        // The 'optimizer' archetype prompt returns plain text (for OptimizerProfileAgent),
        // NOT JSON — so InterviewOptimizerAgent must never use it.
        $archetype = Archetype::create([
            'name'               => 'TTRPG Fantasy 2',
            'qdrant_vector_name' => 'fantasy_v2',
        ]);

        ArchetypePrompt::create([
            'archetype_id'  => $archetype->id,
            'agent_type'    => 'optimizer',
            'system_prompt' => 'Plain text optimizer — should never be used here.',
        ]);

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                // Must NOT use the plain-text 'optimizer' prompt
                return $messages[0]['content'] !== 'Plain text optimizer — should never be used here.';
            })
            ->andReturn(['text' => json_encode(['optimized_fields' => ['preferences' => 'fantasy lore']])]);

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize(['preferences' => 'fantasy stuff'], $archetype);

        $this->assertArrayHasKey('preferences', $result);
    }

    public function test_optimize_filters_trivial_values_in_response(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => json_encode([
                'optimized_fields' => [
                    'preferences' => 'ok',      // len=2 → discarded
                    'style'       => 'drama thriller action',
                ],
            ])]);

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize([
            'preferences' => 'some preference',
            'style'       => 'some style',
        ]);

        $this->assertArrayNotHasKey('preferences', $result);
        $this->assertArrayHasKey('style', $result);
    }

    public function test_optimize_strips_markdown_code_blocks(): void
    {
        $jsonPayload = json_encode([
            'optimized_fields' => ['preferences' => 'cyberpunk noir detective'],
        ]);

        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => "```json\n{$jsonPayload}\n```"]);

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize(['preferences' => 'cyberpunk stuff']);

        $this->assertEquals('cyberpunk noir detective', $result['preferences']);
    }

    public function test_optimize_accepts_flat_json_without_optimized_fields_key(): void
    {
        // Some models return flat {key: value} instead of {optimized_fields: {key: value}}
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => json_encode([
                'preferences' => 'epic fantasy adventure',
            ])]);

        $agent = new InterviewOptimizerAgent($this->gateway, $this->resolver);

        $result = $agent->optimize(['preferences' => 'fantasy']);

        $this->assertArrayHasKey('preferences', $result);
    }
}
