<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\VaultOptimizerAgent;
use App\Support\UserAiSettingsResolver;
use RuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class VaultOptimizerAgentTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    private UserAiSettingsResolver $settingsResolver;
    private AiChatGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();

        $this->settingsResolver = $this->createMock(UserAiSettingsResolver::class);
        $this->gateway = $this->createMock(AiChatGateway::class);
    }

    public function test_optimize_returns_parsed_array_on_valid_json_response(): void
    {
        $this->settingsResolver->method('resolveAgentModel')->willReturn('optimizer-model');

        $expectedJson = json_encode([
            'name_es'            => 'El Refugio',
            'name_en'            => 'The Refuge',
            'optimized_text_en'  => 'A quiet place.',
            'semantic_tag_query' => 'quiet place, safe haven, hidden bunker',
        ]);

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => $expectedJson]);

        $agent = new VaultOptimizerAgent($this->gateway, $this->settingsResolver);

        $result = $agent->optimize('Refugio', 'Un lugar seguro.');

        $this->assertEquals([
            'name_es'            => 'El Refugio',
            'name_en'            => 'The Refuge',
            'optimized_text_en'  => 'A quiet place.',
            'semantic_tag_query' => 'quiet place, safe haven, hidden bunker',
        ], $result);
    }

    public function test_optimize_throws_runtime_exception_on_invalid_json(): void
    {
        $this->settingsResolver->method('resolveAgentModel')->willReturn('optimizer-model');

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => 'Not a json']);

        $agent = new VaultOptimizerAgent($this->gateway, $this->settingsResolver);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error parseando respuesta JSON del LLM.');

        $agent->optimize('Refugio', 'Un lugar seguro.');
    }

    public function test_optimize_strips_markdown_fences_before_parsing(): void
    {
        $this->settingsResolver->method('resolveAgentModel')->willReturn('optimizer-model');

        $expectedJson = "```json\n" . json_encode([
            'name_es'            => 'La Fortaleza',
            'name_en'            => 'The Fortress',
            'optimized_text_en'  => 'An impenetrable base.',
            'semantic_tag_query' => 'military base, high security, fortified outpost',
        ]) . "\n```";

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => $expectedJson]);

        $agent = new VaultOptimizerAgent($this->gateway, $this->settingsResolver);

        $result = $agent->optimize('Fortaleza', 'Base inexpugnable.');

        $this->assertEquals([
            'name_es'            => 'La Fortaleza',
            'name_en'            => 'The Fortress',
            'optimized_text_en'  => 'An impenetrable base.',
            'semantic_tag_query' => 'military base, high security, fortified outpost',
        ], $result);
    }

    public function test_optimize_throws_if_semantic_tag_query_missing(): void
    {
        $this->settingsResolver->method('resolveAgentModel')->willReturn('optimizer-model');

        $jsonMissingField = json_encode([
            'name_es'           => 'El Refugio',
            'name_en'           => 'The Refuge',
            'optimized_text_en' => 'A quiet place.',
        ]);

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => $jsonMissingField]);

        $agent = new VaultOptimizerAgent($this->gateway, $this->settingsResolver);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON de respuesta incompleto.');

        $agent->optimize('Refugio', 'Un lugar seguro.');
    }
}
