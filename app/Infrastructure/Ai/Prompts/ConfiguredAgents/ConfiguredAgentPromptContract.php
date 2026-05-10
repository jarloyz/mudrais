<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

interface ConfiguredAgentPromptContract
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function buildMessages(array $payload): array;

    /**
     * @return array{temperature:float,max_output_tokens:int}
     */
    public function defaults(): array;
}
