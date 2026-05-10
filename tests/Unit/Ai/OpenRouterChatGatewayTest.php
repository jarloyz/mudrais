<?php

namespace Tests\Unit\Ai;

use App\Infrastructure\Ai\OpenRouterChatGateway;
use App\Models\ProviderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class OpenRouterChatGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_request_and_response_without_exposing_api_key(): void
    {
        config()->set('historia.ai.openrouter.api_key', 'secret-test-key');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Respuesta del modelo.',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 12,
                    'completion_tokens' => 8,
                    'total_tokens' => 20,
                ],
            ], 200),
        ]);

        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        $gateway = new OpenRouterChatGateway($logger);

        $result = $gateway->chat(
            model: 'x-ai/grok-4.1-fast',
            messages: [
                ['role' => 'system', 'content' => 'Sistema de prueba'],
                ['role' => 'user', 'content' => 'Hola mundo'],
            ],
            temperature: 0.7,
            maxOutputTokens: 1200,
            timeoutMs: 90000,
            cacheControl: ['type' => 'ephemeral'],
            options: [
                'top_p' => 0.92,
                'presence_penalty' => 0.25,
                'frequency_penalty' => 0.1,
                'user' => '1',
                'session_id' => 'scene:demo|user:1',
                'metadata' => ['scene_id' => 'scene_demo', 'agent' => 'writer'],
                'agent' => 'openrouter_agent',
            ],
        );

        $this->assertSame('Respuesta del modelo.', $result['text']);
        $this->assertCount(2, $entries);
        $this->assertSame('OpenRouter request prepared', $entries[0]['message']);
        $this->assertSame('OpenRouter response received', $entries[1]['message']);
        $this->assertSame('x-ai/grok-4.1-fast', $entries[0]['context']['model']);
        $this->assertSame('Hola mundo', $entries[0]['context']['request']['messages'][1]['content']);
        $this->assertSame('scene:demo|user:1', $entries[0]['context']['request']['session_id']);
        $this->assertSame(0.92, $entries[0]['context']['request']['top_p']);
        $this->assertSame('Respuesta del modelo.', $entries[1]['context']['text_preview']);
        $this->assertStringNotContainsString('secret-test-key', json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertDatabaseHas('provider_logs', [
            'agent' => 'openrouter_agent',
            'model' => 'x-ai/grok-4.1-fast',
            'total_tokens' => 20,
            'status' => 'success',
        ]);
    }

    public function test_chat_parses_tool_calls_correctly(): void
    {
        config()->set('historia.ai.openrouter.api_key', 'secret-key');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thinking...',
                            'tool_calls' => [
                                [
                                    'id' => 'call_123',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'get_weather',
                                        'arguments' => '{"city": "Madrid"}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 15,
                    'total_tokens' => 25,
                ],
            ], 200),
        ]);

        $entries = [];
        $gateway = new OpenRouterChatGateway(new ArrayStructuredLogger($entries));

        $result = $gateway->chat(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Weather in Madrid?']],
            temperature: 0.7,
            maxOutputTokens: 1000,
            options: ['agent' => 'tool_agent']
        );

        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('call_123', $result['tool_calls'][0]['id']);
        $this->assertSame('function', $result['tool_calls'][0]['type']);
        $this->assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        $this->assertSame('{"city": "Madrid"}', $result['tool_calls'][0]['function']['arguments']);

        $this->assertDatabaseHas('provider_logs', [
            'agent' => 'tool_agent',
            'model' => 'test-model',
            'total_tokens' => 25,
            'status' => 'success',
        ]);
    }
}
