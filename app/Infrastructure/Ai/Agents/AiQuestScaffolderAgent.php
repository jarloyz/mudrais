<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Agents\QuestScaffolderAgent;
use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Infrastructure\Ai\Prompts\QuestScaffolderPrompt;
use App\Support\LogPreview;
use App\Support\UserAiSettingsResolver;

final readonly class AiQuestScaffolderAgent implements QuestScaffolderAgent
{
    public function __construct(
        private AiChatGateway $aiChatGateway,
        private UserAiSettingsResolver $userAiSettingsResolver,
        private StructuredLogger $logger,
    ) {
    }

    public function generate(string $prompt, ?string $userId = null): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return $this->fallbackScaffold('Quest base', 'Quest generada sin prompt suficiente.');
        }

        $model = $this->userAiSettingsResolver->resolveAgentModel($userId, 'quest_scaffolder');
        $settings = $this->userAiSettingsResolver->resolve($userId);
        $messages = QuestScaffolderPrompt::buildMessages($prompt);
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'agent' => 'quest_scaffolder',
            'userId' => $userId,
            'model' => $model,
        ]);

        $logger->info('Inicio de scaffold de quest');
        $logger->debug('Quest scaffolder payload preparado', [
            'prompt_preview' => LogPreview::text($prompt, 3000),
            'messages_preview' => LogPreview::messages($messages, 4000),
        ]);

        $response = $this->aiChatGateway->chat(
            model: $model,
            messages: $messages,
            temperature: 0.2,
            maxOutputTokens: 1000,
            timeoutMs: $settings['timeout_ms'],
            cacheControl: null,
            onChunk: null,
            options: [
                'user' => $userId !== null ? (string) $userId : null,
                'metadata' => [
                    'agent' => 'quest_scaffolder',
                ],
            ],
        );

        $scaffold = $this->normalizeScaffold((string) ($response['text'] ?? ''), $prompt);

        $logger->info('Scaffold de quest completado', [
            'title' => $scaffold['title'],
            'stepCount' => count($scaffold['steps']),
        ]);

        return $scaffold;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeScaffold(string $text, string $prompt): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/```$/', '', $clean) ?? $clean;
        $clean = trim($clean);
        $decoded = json_decode($clean, true);

        if (! is_array($decoded)) {
            return $this->fallbackScaffold($prompt, $prompt);
        }

        $title = trim((string) ($decoded['title'] ?? ''));
        $description = trim((string) ($decoded['description'] ?? ''));
        $type = trim((string) ($decoded['type'] ?? 'main'));
        $status = trim((string) ($decoded['status'] ?? 'active'));
        $steps = is_array($decoded['steps'] ?? null) ? $decoded['steps'] : [];

        $normalizedSteps = collect($steps)
            ->filter(fn ($step) => is_array($step) && trim((string) ($step['description'] ?? '')) !== '')
            ->map(function (array $step, int $index): array {
                $stageNumber = is_numeric($step['stage_number'] ?? null)
                    ? (int) $step['stage_number']
                    : (($index + 1) * 10);

                return [
                    'stage_number' => $stageNumber,
                    'description' => trim((string) $step['description']),
                    'is_optional' => (bool) ($step['is_optional'] ?? false),
                ];
            })
            ->sortBy('stage_number')
            ->values()
            ->all();

        if ($title === '' || count($normalizedSteps) < 3) {
            return $this->fallbackScaffold($prompt, $description !== '' ? $description : $prompt);
        }

        return [
            'title' => $title,
            'description' => $description !== '' ? $description : $title,
            'type' => $type !== '' ? $type : 'main',
            'status' => $status !== '' ? $status : 'active',
            'steps' => $normalizedSteps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackScaffold(string $titleSeed, string $descriptionSeed): array
    {
        $title = trim($titleSeed) !== '' ? mb_substr(trim($titleSeed), 0, 80) : 'Quest base';

        return [
            'title' => $title,
            'description' => trim($descriptionSeed) !== '' ? trim($descriptionSeed) : $title,
            'type' => 'main',
            'status' => 'active',
            'steps' => [
                ['stage_number' => 10, 'description' => 'Identifica el objetivo inmediato.', 'is_optional' => false],
                ['stage_number' => 20, 'description' => 'Supera el obstaculo principal.', 'is_optional' => false],
                ['stage_number' => 30, 'description' => 'Asegura una salida o ventaja clara.', 'is_optional' => false],
            ],
        ];
    }
}
