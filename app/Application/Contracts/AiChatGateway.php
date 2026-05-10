<?php

namespace App\Application\Contracts;

interface AiChatGateway
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param callable(string):void|null $onChunk
     * @param array<int, mixed>|null $tools
     * @return array{text:string,usage:array<string,mixed>|null,raw:mixed,tool_calls:array<int, array{id:string, name:string, arguments:array<string,mixed>}>|null}
     */
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
    ): array;

    /**
     * @return array<int, float>
     */
    public function embeddings(string $model, string $text): array;
}
