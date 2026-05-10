<?php

namespace Tests\Unit\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\ContextOptimizerAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ContextOptimizerAgentTest extends TestCase
{
    private $gateway;
    private $settingsResolver;
    private $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = Mockery::mock(AiChatGateway::class);
        $this->settingsResolver = Mockery::mock(UserAiSettingsResolver::class);
        $this->agent = new ContextOptimizerAgent($this->gateway, $this->settingsResolver);
    }

    public function test_optimize_success_with_perfect_json()
    {
        $this->settingsResolver->shouldReceive('resolveAgentModel')->andReturn('test-model');

        $this->gateway->shouldReceive('chat')->andReturn([
            'text' => '{"optimized_text_en": "Perfect response", "semantic_tag_query": "tags here"}'
        ]);

        $result = $this->agent->optimize('some prompt');

        $this->assertEquals('Perfect response', $result['optimized_text_en']);
        $this->assertEquals('tags here', $result['semantic_tag_query']);
    }

    public function test_optimize_success_with_markdown_fences()
    {
        $this->settingsResolver->shouldReceive('resolveAgentModel')->andReturn('test-model');

        $this->gateway->shouldReceive('chat')->andReturn([
            'text' => "```json\n{\"optimized_text_en\": \"Markdown response\", \"semantic_tag_query\": \"tags here\"}\n```"
        ]);

        $result = $this->agent->optimize('some prompt');

        $this->assertEquals('Markdown response', $result['optimized_text_en']);
    }

    public function test_optimize_success_with_buried_json()
    {
        $this->settingsResolver->shouldReceive('resolveAgentModel')->andReturn('test-model');

        $this->gateway->shouldReceive('chat')->andReturn([
            'text' => "Here is the result:\n{\"optimized_text_en\": \"Buried response\", \"semantic_tag_query\": \"tags here\"}\nHope this helps!"
        ]);

        $result = $this->agent->optimize('some prompt');

        $this->assertEquals('Buried response', $result['optimized_text_en']);
    }

    public function test_optimize_sends_system_enforcement_message()
    {
        $this->settingsResolver->shouldReceive('resolveAgentModel')->andReturn('test-model');

        $this->gateway->shouldReceive('chat')
            ->withArgs(function ($model, $messages) {
                return isset($messages[0]['role'])
                    && $messages[0]['role'] === 'system'
                    && str_contains($messages[0]['content'], 'optimized_text_en')
                    && str_contains($messages[0]['content'], 'semantic_tag_query');
            })
            ->once()
            ->andReturn(['text' => '{"optimized_text_en":"x","semantic_tag_query":"y"}']);

        $result = $this->agent->optimize('some prompt');

        $this->assertArrayHasKey('optimized_text_en', $result);
        $this->assertArrayHasKey('semantic_tag_query', $result);
    }

    public function test_optimize_uses_at_least_1500_max_tokens(): void
    {
        $this->settingsResolver->shouldReceive('resolveAgentModel')->andReturn('test-model');

        $this->gateway->shouldReceive('chat')
            ->withArgs(function ($model, $messages, $temperature, $maxTokens) {
                return $maxTokens >= 1500;
            })
            ->once()
            ->andReturn(['text' => '{"optimized_text_en":"x","semantic_tag_query":"y"}']);

        $this->agent->optimize('some prompt');
    }

    public function test_optimize_truncates_oversized_prompt(): void
    {
        $this->settingsResolver->shouldReceive('resolveAgentModel')->andReturn('test-model');

        $this->gateway->shouldReceive('chat')
            ->withArgs(function ($model, $messages) {
                $userContent = $messages[1]['content'] ?? '';
                return mb_strlen($userContent) <= 9000;
            })
            ->once()
            ->andReturn(['text' => '{"optimized_text_en":"x","semantic_tag_query":"y"}']);

        $result = $this->agent->optimize(str_repeat('a', 20000));

        $this->assertSame('x', $result['optimized_text_en']);
    }

    public function test_optimize_fails_on_invalid_json()
    {
        $this->settingsResolver->shouldReceive('resolveAgentModel')->andReturn('test-model');

        $this->gateway->shouldReceive('chat')->andReturn([
            'text' => "This is not JSON at all."
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LLM devolvió JSON inválido');

        $this->agent->optimize('some prompt');
    }
}
