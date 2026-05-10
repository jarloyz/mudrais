<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Support\LogPreview;
use App\Support\ConfiguredAgentPromptRegistry;
use App\Support\UserAiSettingsResolver;
use RuntimeException;

class GenericConfiguredAgent
{
    public function __construct(
        private readonly AiChatGateway $aiChatGateway,
        private readonly UserAiSettingsResolver $userAiSettingsResolver,
        private readonly ConfiguredAgentPromptRegistry $promptRegistry,
        private readonly StructuredLogger $logger,
        private readonly string $agentKey,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{text:string,usage:array<string,mixed>|null,raw:mixed}
     */
    public function generate(array $payload, ?string $userId = null): array
    {
        $prompt = $this->promptRegistry->for($this->agentKey);
        $defaults = $prompt->defaults();
        $settings = $this->userAiSettingsResolver->resolve($userId);
        $model = $this->userAiSettingsResolver->resolveAgentModel($userId, $this->agentKey);
        $messages = $prompt->buildMessages($payload);
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'agent' => $this->agentKey,
            'userId' => $userId,
            'model' => $model,
        ]);

        $logger->info('Inicio de agente configurable generico');
        $logger->debug('Agente configurable payload preparado', [
            'payload_preview' => LogPreview::json($payload, 12000),
            'messages_preview' => LogPreview::messages($messages, 4000),
            'temperature' => $defaults['temperature'],
            'max_output_tokens' => $defaults['max_output_tokens'],
            'timeout_ms' => $settings['timeout_ms'],
        ]);

        $response = $this->aiChatGateway->chat(
            model: $model,
            messages: $messages,
            temperature: $defaults['temperature'],
            maxOutputTokens: $defaults['max_output_tokens'],
            timeoutMs: $settings['timeout_ms'],
            cacheControl: null,
            onChunk: null,
            options: [
                'user' => $userId !== null ? (string) $userId : null,
                'metadata' => array_filter([
                    'agent' => $this->agentKey,
                ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            ],
        );

        $text = trim((string) ($response['text'] ?? ''));
        if ($text === '') {
            $logger->error('Agente configurable devolvio salida vacia');
            throw new RuntimeException("El agente {$this->agentKey} no devolvio texto.");
        }

        $logger->info('Agente configurable completado', [
            'outputChars' => mb_strlen($text),
            'outputPreview' => LogPreview::text($text, 4000),
            'usage' => $response['usage'] ?? null,
        ]);

        return [
            'text' => $text,
            'usage' => $response['usage'] ?? null,
            'raw' => $response['raw'] ?? null,
        ];
    }
}
