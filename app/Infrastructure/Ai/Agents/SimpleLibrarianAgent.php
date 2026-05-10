<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Agents\LibrarianAgent;
use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\SimpleLibrarianPrompt;
use App\Support\UserAiSettingsResolver;

final readonly class SimpleLibrarianAgent implements LibrarianAgent
{
    public function __construct(
        private AiChatGateway $aiChatGateway,
        private UserAiSettingsResolver $userAiSettingsResolver,
        private StructuredLogger $logger,
    ) {
    }

    public function evaluate(Activity $scene, array $context, string $userMessage, ?string $userId = null): array
    {
        $settings = $this->userAiSettingsResolver->resolve($userId);
        $librarianModel = (string) ($settings['models']['librarian'] ?? '');

        $messages = SimpleLibrarianPrompt::buildMessages($scene, $context, $userMessage, $settings['parameters']['librarian'] ?? []);
        $tools = SimpleLibrarianPrompt::getTools();

        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'agent' => 'librarian',
            'sceneId' => $scene->id,
            'userId' => $userId,
            'model' => $librarianModel,
        ]);

        $logger->info('Librarian evaluation start');

        $response = $this->aiChatGateway->chat(
            model: $librarianModel,
            messages: $messages,
            temperature: (float) ($settings['parameters']['librarian']['temperature'] ?? 0.1),
            maxOutputTokens: (int) ($settings['parameters']['librarian']['max_output_tokens'] ?? 1000),
            timeoutMs: $settings['timeout_ms'],
            tools: $tools
        );

        $toolCalls = $response['tool_calls'] ?? [];
        $searchQuery = null;
        $reason = null;

        foreach ($toolCalls as $tc) {
            if (($tc['function']['name'] ?? '') === 'search_knowledge_base') {
                $args = is_string($tc['function']['arguments'])
                    ? json_decode($tc['function']['arguments'], true)
                    : $tc['function']['arguments'];

                $searchQuery = $args['query'] ?? null;
                $reason = $args['reason'] ?? null;
                break;
            }
        }

        $result = [
            'needs_search' => $searchQuery !== null,
            'query' => $searchQuery,
            'reason' => $reason,
            'analysis' => $response['text'] ?? '',
        ];

        $logger->info('Librarian evaluation complete', [
            'needs_search' => $result['needs_search'],
            'query' => $result['query']
        ]);

        return $result;
    }
}
