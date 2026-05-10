<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Agents\SummarizerAgent;
use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Infrastructure\Ai\Prompts\SummarizerPrompt;
use App\Support\UserAiSettingsResolver;
use RuntimeException;

final readonly class ConfiguredSummarizerAgent implements SummarizerAgent
{
    public function __construct(
        private AiChatGateway $aiChatGateway,
        private UserAiSettingsResolver $userAiSettingsResolver,
        private StructuredLogger $logger,
    ) {
    }

    public function summarizeIncremental(string $sceneId, string $existingSummary, array $messages, ?string $userId = null): string
    {
        $settings = $this->userAiSettingsResolver->resolve($userId);
        $model = $this->userAiSettingsResolver->resolveAgentModel($userId, 'summarizer');
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'agent' => 'summarizer',
            'sceneId' => $sceneId,
            'userId' => $userId,
            'model' => $model,
            'messageCount' => count($messages),
        ]);

        $logger->info('Inicio de resumen incremental');

        $response = $this->aiChatGateway->chat(
            model: $model,
            messages: SummarizerPrompt::buildIncrementalMessages($sceneId, $existingSummary, $messages),
            temperature: 0.3,
            maxOutputTokens: 1400,
            timeoutMs: $settings['timeout_ms'],
            cacheControl: null,
            onChunk: null,
            options: [
                'user' => $userId !== null ? (string) $userId : null,
                'session_id' => "scene:{$sceneId}|agent:summarizer|user:".($userId ?? 0),
                'metadata' => array_filter([
                    'scene_id' => $sceneId,
                    'agent' => 'summarizer',
                ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            ],
        );

        $text = trim((string) ($response['text'] ?? ''));
        $text = preg_replace('/^```(?:markdown|md|text)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/```$/', '', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            $logger->error('Summarizer devolvio salida vacia');
            throw new RuntimeException('El summarizer no devolvio texto util.');
        }

        $logger->info('Resumen incremental completado', [
            'summaryChars' => mb_strlen($text),
        ]);

        return $text;
    }
}
