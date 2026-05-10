<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\ComplexSceneWriterPrompt;
use App\Support\LogPreview;
use App\Support\UserAiSettingsResolver;
use RuntimeException;

final readonly class ComplexSceneWriterAgent
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
        $writerModel = (string) ($settings['models']['writer'] ?? '');
        $messages = ComplexSceneWriterPrompt::buildMessages($context, $userMessage, $mode);
        $tools = ComplexSceneWriterPrompt::getTools();

        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'agent' => 'complex_writer',
            'sceneId' => $scene->id,
            'userId' => $userId,
            'mode' => $mode,
            'model' => $writerModel,
        ]);

        $logger->info('Inicio de writer complejo');

        $writerProvider = $this->userAiSettingsResolver->resolveAgentProvider($userId, 'writer');
        $options = $this->buildOptions($scene, $context, $mode, $userId, $settings);
        if ($writerProvider !== null) {
            $options['_provider'] = $writerProvider;
        }

        try {
            $response = $this->aiChatGateway->chat(
                model: $writerModel,
                messages: $messages,
                temperature: (float) ($settings['parameters']['writer']['temperature'] ?? 0.7),
                maxOutputTokens: (int) ($settings['parameters']['writer']['max_output_tokens'] ?? 4000),
                timeoutMs: $settings['timeout_ms'],
                onChunk: $onChunk,
                options: $options,
                tools: $tools,
            );
        } catch (\Throwable $e) {
            $logger->error('[ComplexSceneWriterAgent@generate] Excepción al llamar al gateway', [
                'message' => $e->getMessage(),
                'provider' => $writerProvider,
                'model' => $writerModel,
            ]);
            throw $e;
        }

        $text = trim((string) ($response['text'] ?? ''));
        if ($text === '' && empty($response['tool_calls'])) {
            $logger->error('Writer complejo devolvio salida vacia');
            throw new RuntimeException('La IA no devolvió contenido para la escena compleja');
        }

        $outputMd = $this->normalizeOutput($text);

        // Process tool calls (merged with any data already extracted from JSON)
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

        $logger->info('Writer complejo completado', [
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

    private function buildOptions(Activity $scene, array $context, string $mode, ?string $userId, array $settings): array
    {
        $sessionParts = ['scene:'.$scene->id, 'mode:'.$mode, 'user:'.($userId ?? ''), 'type:complex'];
        return [
            'user' => $userId !== null ? (string) $userId : null,
            'session_id' => implode('|', $sessionParts),
            'metadata' => [
                'scene_id' => $scene->id,
                'agent' => 'complex_writer',
            ],
        ];
    }

    private function normalizeOutput(string $text): string
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json|markdown|md)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/```$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        if (str_starts_with($clean, '{')) {
            $decoded = json_decode($clean, true);
            if (is_array($decoded)) {
                $narrative = trim((string) ($decoded['narrative'] ?? $decoded['scene_addition_md'] ?? $decoded['scene_rewrite_md'] ?? ''));
                if ($narrative !== '') {
                    return $narrative;
                }
            }
        }

        return $clean;
    }
}
