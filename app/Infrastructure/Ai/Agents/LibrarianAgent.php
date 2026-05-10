<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Support\UserAiSettingsResolver;
use App\Application\Contracts\StructuredLogger;
use App\Infrastructure\Ai\Prompts\LibrarianPrompt;

class LibrarianAgent
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $settingsResolver,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * @return array{action:string, query?:string, intent?:string}|null
     */
    public function analyzeNeeds(array $hotMemory, string $userMessage, ?string $userId = null): ?array
    {
        $model = $this->settingsResolver->resolveAgentModel($userId, 'librarian');

        $prompt = LibrarianPrompt::buildInstruction($hotMemory);
        $tools = LibrarianPrompt::getTools();

        $response = $this->gateway->chat(
            model: $model,
            messages: [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $userMessage]
            ],
            temperature: 0.1,
            maxOutputTokens: 300,
            timeoutMs: null,
            cacheControl: null,
            onChunk: null,
            options: [],
            tools: $tools
        );

        $toolCalls = $response['tool_calls'] ?? null;

        if ($toolCalls && count($toolCalls) > 0) {
            $call = $toolCalls[0];
            $name = $call['function']['name'] ?? $call['name'] ?? '';
            $argsStr = $call['function']['arguments'] ?? $call['arguments'] ?? '{}';
            $args = is_string($argsStr) ? json_decode($argsStr, true) : $argsStr;

            $this->logger->info('Librarian Tool Call', ['name' => $name, 'args' => $args]);

            if ($name === 'search_knowledge') {
                return [
                    'action' => 'search_knowledge',
                    'query' => $args['query'] ?? '',
                    // Propagar contexto de linaje para filtrado RAG versionado
                    'lineage_context' => $this->extractLineageContext($hotMemory),
                ];
            }
            if ($name === 'search_inventory') {
                return ['action' => 'search_inventory', 'intent' => $args['intent'] ?? ''];
            }
        }

        $text = trim($response['text'] ?? '');
        if (strtoupper($text) !== 'SKIP' && !empty($text)) {
            return [
                'action' => 'search_knowledge',
                'query' => $text,
                'lineage_context' => $this->extractLineageContext($hotMemory),
            ];
        }

        return null;
    }

    /**
     * Extrae el contexto de linaje del hot memory para el filtrado RAG versionado.
     *
     * @param array<string, mixed> $hotMemory
     * @return array{lineage_id:string|null, version:int}
     */
    private function extractLineageContext(array $hotMemory): array
    {
        return [
            'lineage_id' => isset($hotMemory['character']['id'])
                ? (string) $hotMemory['character']['id']
                : null,
            'version' => isset($hotMemory['character']['snapshot_version'])
                ? (int) $hotMemory['character']['snapshot_version']
                : 1,
        ];
    }
}
