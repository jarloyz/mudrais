<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\AiChatGateway;

class ThinkingAiChatGateway implements AiChatGateway
{
    private const DEFAULT_BUDGET_TOKENS = 8000;

    public function __construct(
        private AiChatGateway $inner,
        private int $budgetTokens = self::DEFAULT_BUDGET_TOKENS,
    ) {}

    public function chat(
        string $model,
        array $messages,
        float $temperature,
        int $maxOutputTokens,
        ?int $timeoutMs = null,
        ?array $cacheControl = null,
        ?callable $onChunk = null,
        array $options = [],
        ?array $tools = null,
    ): array {
        if (! isset($options['reasoning'])) {
            $options['reasoning'] = ['enabled' => true, 'budget_tokens' => $this->budgetTokens];
        }

        return $this->inner->chat(
            $model,
            $messages,
            $temperature,
            $maxOutputTokens,
            $timeoutMs,
            $cacheControl,
            $onChunk,
            $options,
            $tools
        );
    }

    public function embeddings(string $model, string $text): array
    {
        return $this->inner->embeddings($model, $text);
    }
}
