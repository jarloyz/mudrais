<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Agents\SceneWriterAgent;
use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\SimpleSceneWriterPrompt;
use App\Support\LogPreview;
use App\Support\UserAiSettingsResolver;
use RuntimeException;

final readonly class SimpleSceneWriterAgent implements SceneWriterAgent
{
    public function __construct(
        private AiChatGateway $aiChatGateway,
        private UserAiSettingsResolver $userAiSettingsResolver,
        private StructuredLogger $logger,
    ) {
    }

    public function generate(Activity $scene, array $context, string $userMessage, string $mode, ?callable $onChunk = null, ?string $userId = null): array
    {
        $settings = $this->userAiSettingsResolver->resolve($userId);
        $messages = $this->buildMessages($scene, $context, $userMessage, $mode, $settings);
        $writerModel = (string) ($settings['models']['writer'] ?? '');
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'agent' => 'writer',
            'sceneId' => $scene->id,
            'userId' => $userId,
            'mode' => $mode,
            'model' => $writerModel,
        ]);

        $logger->info('Inicio de writer simple');
        $logger->debug('Writer simple payload preparado', [
            'user_message_preview' => LogPreview::text($userMessage, 3000),
            'context_preview' => LogPreview::json([
                'continuity_id' => $context['continuity_id'] ?? null,
                'location' => $context['location'] ?? null,
                'characters' => array_slice((array) ($context['characters'] ?? []), 0, 8),
                'rolling_summary' => $context['rolling_summary'] ?? null,
                'scene_opening' => $context['scene_opening'] ?? null,
                'recent_messages' => array_slice((array) ($context['recent_messages'] ?? []), -4),
            ], 12000),
            'messages_preview' => LogPreview::messages($messages, 4000),
            'writer_options' => $this->buildWriterOptions($scene, $context, $mode, $userId, $settings),
            'cache_control' => $this->resolveWriterCacheControl($writerModel),
        ]);

        $writerProvider = $this->userAiSettingsResolver->resolveAgentProvider($userId, 'writer');
        $options = $this->buildWriterOptions($scene, $context, $mode, $userId, $settings);
        if ($writerProvider !== null) {
            $options['_provider'] = $writerProvider;
        }

        try {
            $cacheControl = $this->resolveWriterCacheControl($writerModel);
            $tools = SimpleSceneWriterPrompt::getTools();

            $response = $this->aiChatGateway->chat(
                model: $writerModel,
                messages: $messages,
                temperature: (float) ($settings['parameters']['writer']['temperature'] ?? 0.7),
                maxOutputTokens: (int) ($settings['parameters']['writer']['max_output_tokens'] ?? 4000),
                timeoutMs: $settings['timeout_ms'],
                cacheControl: $cacheControl,
                onChunk: $onChunk,
                options: $options,
                tools: $tools,
            );
        } catch (\Throwable $e) {
            $logger->error('[SimpleSceneWriterAgent@generate] Excepción al llamar al gateway', [
                'message' => $e->getMessage(),
                'provider' => $writerProvider,
                'model' => $writerModel,
            ]);
            throw $e;
        }

        $text = trim((string) ($response['text'] ?? ''));
        if ($text === '' && empty($response['tool_calls'])) {
            $logger->error('Writer simple devolvio salida vacia (sin texto ni tools)');
            throw new RuntimeException('La IA no devolvió contenido para la escena');
        }

        $outputMd = $this->normalizeOutput($text, $scene, $userMessage);

        // Process tool calls
        $notes = [];
        $stateChanges = [];
        foreach ($response['tool_calls'] ?? [] as $tc) {
            $name = $tc['function']['name'] ?? '';
            $args = is_string($tc['function']['arguments'])
                ? json_decode($tc['function']['arguments'], true)
                : $tc['function']['arguments'];

            if ($name === 'update_character_status') {
                foreach ($args['changes'] ?? [] as $change) {
                    $stateChanges[] = $change;
                }
            } elseif ($name === 'emit_narrative_notes') {
                foreach ($args['notes'] ?? [] as $note) {
                    $notes[] = $note;
                }
            }
        }

        $logger->info('Writer simple completado', [
            'outputChars' => mb_strlen($outputMd),
            'noteCount' => count($notes),
            'stateChangeCount' => count($stateChanges),
        ]);

        return [
            'outputMd' => $outputMd,
            'notes' => $notes,
            'stateChanges' => $stateChanges,
        ];
    }

    private function resolveWriterCacheControl(string $writerModel): ?array
    {
        return str_starts_with($writerModel, 'anthropic/')
            ? config('historia.ai.cache_control')
            : null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildWriterOptions(Activity $scene, array $context, string $mode, ?string $userId, array $settings): array
    {
        $continuityId = is_string($context['continuity_id'] ?? null) ? trim((string) $context['continuity_id']) : '';
        $parameterSet = is_array($settings['parameters']['writer'] ?? null) ? $settings['parameters']['writer'] : [];
        $sessionParts = [
            'scene:'.$scene->id,
            'mode:'.$mode,
            'user:'.($userId ?? ''),
        ];

        if ($continuityId !== '') {
            $sessionParts[] = 'cont:'.$continuityId;
        }

        return [
            'top_p' => $parameterSet['top_p'] ?? null,
            'presence_penalty' => $parameterSet['presence_penalty'] ?? null,
            'frequency_penalty' => $parameterSet['frequency_penalty'] ?? null,
            'user' => $userId !== null ? (string) $userId : null,
            'session_id' => implode('|', $sessionParts),
            'metadata' => array_filter([
                'scene_id' => $scene->id,
                'continuity_id' => $continuityId !== '' ? $continuityId : null,
                'mode' => $mode,
                'agent' => 'writer',
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $settings
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(Activity $scene, array $context, string $userMessage, string $mode, array $settings): array
    {
        return SimpleSceneWriterPrompt::buildMessages(
            $scene,
            $context,
            $userMessage,
            $mode,
            $settings['parameters']['writer'] ?? [],
        );
    }

    private function normalizeOutput(string $text, Activity $scene, string $userMessage): string
    {
        $clean = trim($text);
        // Remove markdown fences
        $clean = preg_replace('/^```(?:json|markdown|md)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/```$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        // Simple fallback to JSON if the agent decided to reply with it (e.g., from old tests)
        if (str_starts_with($clean, '{')) {
            $decoded = json_decode($clean, true);
            if (is_array($decoded)) {
                $candidate = trim((string) ($decoded['scene_addition_md'] ?? $decoded['scene_rewrite_md'] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if ($clean === $userMessage || $clean === (string) $scene->draft) {
            throw new RuntimeException('La IA devolvió una salida no narrativa o inútil');
        }

        return $clean;
    }
}
