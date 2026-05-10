<?php

namespace Tests\Unit\Ai;

use App\Infrastructure\Ai\AnthropicChatGateway;
use App\Models\ProviderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicChatGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_sends_correct_payload_and_parses_response(): void
    {
        config()->set('historia.ai.anthropic.api_key', 'anthropic-secret');

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello!'],
                ],
                'model' => 'claude-3-opus-20240229',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ], 200),
        ]);

        $gateway = new AnthropicChatGateway();

        $result = $gateway->chat(
            model: 'claude-3-opus-20240229',
            messages: [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'Hi'],
            ],
            temperature: 0.5,
            maxOutputTokens: 1000,
            options: ['agent' => 'test_agent']
        );

        $this->assertSame('Hello!', $result['text']);
        $this->assertSame(10, $result['usage']['input_tokens']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['model'] === 'claude-3-opus-20240229' &&
                   $data['system'] === 'You are a helpful assistant.' &&
                   $data['messages'][0]['role'] === 'user' &&
                   $data['messages'][0]['content'] === 'Hi';
        });

        $this->assertDatabaseHas('provider_logs', [
            'agent' => 'test_agent',
            'model' => 'claude-3-opus-20240229',
            'total_tokens' => 15,
            'status' => 'success',
        ]);
    }

    public function test_chat_handles_tool_calls_and_results(): void
    {
        config()->set('historia.ai.anthropic.api_key', 'anthropic-secret');

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_tool',
                'content' => [
                    ['type' => 'text', 'text' => 'Thinking...'],
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'get_weather', 'input' => ['city' => 'San Francisco']],
                ],
                'usage' => ['input_tokens' => 20, 'output_tokens' => 30],
            ], 200),
        ]);

        $gateway = new AnthropicChatGateway();

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string']
                        ]
                    ]
                ]
            ]
        ];

        $result = $gateway->chat(
            model: 'claude-3-sonnet',
            messages: [['role' => 'user', 'content' => 'Weather in SF?']],
            temperature: 0,
            maxOutputTokens: 500,
            options: ['agent' => 'weather_agent'],
            tools: $tools
        );

        $this->assertSame('Thinking...', $result['text']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('toolu_123', $result['tool_calls'][0]['id']);
        $this->assertSame('function', $result['tool_calls'][0]['type']);
        $this->assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        $this->assertSame('{"city":"San Francisco"}', $result['tool_calls'][0]['function']['arguments']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $tool = $data['tools'][0];
            return $tool['name'] === 'get_weather' &&
                   isset($tool['input_schema']);
        });

        $this->assertDatabaseHas('provider_logs', [
            'agent' => 'weather_agent',
            'model' => 'claude-3-sonnet',
            'total_tokens' => 50,
            'status' => 'success',
        ]);
    }
}
