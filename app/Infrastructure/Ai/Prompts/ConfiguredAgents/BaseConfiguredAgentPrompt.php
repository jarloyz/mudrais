<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

abstract class BaseConfiguredAgentPrompt implements ConfiguredAgentPromptContract
{
    public function __construct(
        protected readonly string $agentKey,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function buildMessages(array $payload): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->systemInstruction(),
            ],
            [
                'role' => 'user',
                'content' => $this->userInstruction($payload),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function userInstruction(array $payload): string
    {
        return "Payload estructurado para {$this->agentKey}:\n"
            .json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    abstract protected function systemInstruction(): string;
}
