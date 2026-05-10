<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\ArchetypeOptimizerAgent;
use App\Support\UserAiSettingsResolver;
use RuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class ArchetypeOptimizerAgentTest extends TestCase
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
            'name_es'            => 'El Héroe',
            'name_en'            => 'The Hero',
            'optimized_text_en'  => 'Brave and bold playstyle.',
            'semantic_tag_query' => 'heroic archetype, brave combat, bold leadership style',
        ]);

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => $expectedJson]);

        $agent = new ArchetypeOptimizerAgent($this->gateway, $this->settingsResolver);

        $result = $agent->optimize('Héroe', 'Juega de forma valiente.');

        $this->assertEquals([
            'name_es'            => 'El Héroe',
            'name_en'            => 'The Hero',
            'optimized_text_en'  => 'Brave and bold playstyle.',
            'semantic_tag_query' => 'heroic archetype, brave combat, bold leadership style',
        ], $result);
    }

    public function test_optimize_throws_runtime_exception_on_invalid_json(): void
    {
        $this->settingsResolver->method('resolveAgentModel')->willReturn('optimizer-model');

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => 'Not a json']);

        $agent = new ArchetypeOptimizerAgent($this->gateway, $this->settingsResolver);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error parseando respuesta JSON del LLM.');

        $agent->optimize('Héroe', 'Juega de forma valiente.');
    }

    public function test_optimize_strips_markdown_fences_before_parsing(): void
    {
        $this->settingsResolver->method('resolveAgentModel')->willReturn('optimizer-model');

        $expectedJson = "```json\n" . json_encode([
            'name_es'            => 'El Villano',
            'name_en'            => 'The Villain',
            'optimized_text_en'  => 'Evil playstyle.',
            'semantic_tag_query' => 'villain archetype, evil manipulation, dark antagonist role',
        ]) . "\n```";

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => $expectedJson]);

        $agent = new ArchetypeOptimizerAgent($this->gateway, $this->settingsResolver);

        $result = $agent->optimize('Villano', 'Juega de forma malvada.');

        $this->assertEquals([
            'name_es'            => 'El Villano',
            'name_en'            => 'The Villain',
            'optimized_text_en'  => 'Evil playstyle.',
            'semantic_tag_query' => 'villain archetype, evil manipulation, dark antagonist role',
        ], $result);
    }

    public function test_optimize_throws_if_semantic_tag_query_missing(): void
    {
        $this->settingsResolver->method('resolveAgentModel')->willReturn('optimizer-model');

        $jsonMissingField = json_encode([
            'name_es'           => 'El Héroe',
            'name_en'           => 'The Hero',
            'optimized_text_en' => 'Brave playstyle.',
        ]);

        $this->gateway->expects($this->once())
            ->method('chat')
            ->willReturn(['text' => $jsonMissingField]);

        $agent = new ArchetypeOptimizerAgent($this->gateway, $this->settingsResolver);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON de respuesta incompleto.');

        $agent->optimize('Héroe', 'Juega de forma valiente.');
    }
}
