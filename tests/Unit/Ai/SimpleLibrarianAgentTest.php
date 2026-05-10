<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Agents\SimpleLibrarianAgent;
use App\Support\UserAiSettingsResolver;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class SimpleLibrarianAgentTest extends TestCase
{
    public function test_evaluate_detects_lore_search_need(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return [
                    'text' => 'I need to check the history of the Silver Order.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_knowledge_base',
                                'arguments' => json_encode(['query' => 'Silver Order history', 'reason' => 'User asked about their origin']),
                            ],
                        ],
                    ],
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $agent = new SimpleLibrarianAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_librarian',
            'vaultId' => 'vault_test',
            'title' => 'The Library',
            'objective' => 'Research the order',
            'draft' => 'The scene begins in a dark library.',
        ]);

        $result = $agent->evaluate($scene, [], 'Tell me about the Silver Order.');

        $this->assertTrue($result['needs_search']);
        $this->assertSame('Silver Order history', $result['query']);
        $this->assertSame('User asked about their origin', $result['reason']);
    }

    public function test_evaluate_returns_false_when_no_search_needed(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return [
                    'text' => 'The current context is enough to proceed.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => null
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $agent = new SimpleLibrarianAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_librarian',
            'vaultId' => 'vault_test',
            'title' => 'The Tavern',
            'objective' => 'Order a drink',
            'draft' => 'The tavern is full of people.',
        ]);

        $result = $agent->evaluate($scene, [], 'Give me a beer.');

        $this->assertFalse($result['needs_search']);
        $this->assertNull($result['query']);
    }
}
