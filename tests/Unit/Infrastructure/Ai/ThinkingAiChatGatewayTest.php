<?php

namespace Tests\Unit\Infrastructure\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\ThinkingAiChatGateway;
use PHPUnit\Framework\TestCase;

class ThinkingAiChatGatewayTest extends TestCase
{
    public function test_it_injects_thinking_options_if_not_present(): void
    {
        $inner = $this->createMock(AiChatGateway::class);
        $decorator = new ThinkingAiChatGateway($inner, 5000);

        $inner->expects($this->once())
            ->method('chat')
            ->with(
                'test-model',
                [],
                0.7,
                100,
                null,
                null,
                null,
                $this->callback(function ($options) {
                    return isset($options['thinking']) &&
                           $options['thinking']['type'] === 'enabled' &&
                           $options['thinking']['budget_tokens'] === 5000;
                }),
                null
            )
            ->willReturn(['text' => 'hello']);

        $decorator->chat('test-model', [], 0.7, 100);
    }

    public function test_it_does_not_override_existing_thinking_options(): void
    {
        $inner = $this->createMock(AiChatGateway::class);
        $decorator = new ThinkingAiChatGateway($inner, 5000);

        $inner->expects($this->once())
            ->method('chat')
            ->with(
                'test-model',
                [],
                0.7,
                100,
                null,
                null,
                null,
                $this->callback(function ($options) {
                    return isset($options['thinking']) &&
                           $options['thinking']['budget_tokens'] === 1000;
                }),
                null
            )
            ->willReturn(['text' => 'hello']);

        $decorator->chat('test-model', [], 0.7, 100, null, null, null, [
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1000]
        ]);
    }

    public function test_it_delegates_embeddings(): void
    {
        $inner = $this->createMock(AiChatGateway::class);
        $decorator = new ThinkingAiChatGateway($inner);

        $inner->expects($this->once())
            ->method('embeddings')
            ->with('model', 'text')
            ->willReturn([0.1, 0.2]);

        $result = $decorator->embeddings('model', 'text');
        $this->assertEquals([0.1, 0.2], $result);
    }
}
