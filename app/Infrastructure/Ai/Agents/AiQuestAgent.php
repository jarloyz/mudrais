<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Agents\QuestAgent;
use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\QuestAgentPrompt;
use App\Support\LogPreview;
use App\Support\UserAiSettingsResolver;

final readonly class AiQuestAgent implements QuestAgent
{
    public function __construct(
        private AiChatGateway $aiChatGateway,
        private UserAiSettingsResolver $userAiSettingsResolver,
        private StructuredLogger $logger,
    ) {
    }

    public function evaluate(Activity $scene, array $context, string $userMessage, ?string $userId = null): array
    {
        $quests = is_array($context['quests'] ?? null) ? $context['quests'] : [];
        if ($quests === []) {
            return $this->emptyDirective();
        }

        $model = $this->userAiSettingsResolver->resolveAgentModel($userId, 'quest');
        $settings = $this->userAiSettingsResolver->resolve($userId);
        $messages = QuestAgentPrompt::buildMessages($scene, $context, $userMessage);
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'agent' => 'quest',
            'sceneId' => $scene->id,
            'userId' => $userId,
            'model' => $model,
        ]);

        $logger->info('Inicio de preevaluacion de quest', [
            'questCount' => count($quests),
        ]);
        $logger->debug('Quest agent payload preparado', [
            'user_message_preview' => LogPreview::text($userMessage, 3000),
            'quests_preview' => LogPreview::json($quests, 8000),
            'messages_preview' => LogPreview::messages($messages, 4000),
        ]);

        $response = $this->aiChatGateway->chat(
            model: $model,
            messages: $messages,
            temperature: 0.1,
            maxOutputTokens: 600,
            timeoutMs: $settings['timeout_ms'],
            cacheControl: null,
            onChunk: null,
            options: [
                'user' => $userId !== null ? (string) $userId : null,
                'response_format' => ['type' => 'json_object'],
                'metadata' => [
                    'agent' => 'quest',
                    'scene_id' => $scene->id,
                ],
            ],
        );

        $directive = $this->normalizeDirective((string) ($response['text'] ?? ''), $quests);

        $logger->info('Preevaluacion de quest completada', [
            'matched' => $directive['matched'],
            'questId' => $directive['quest_id'],
            'advanceStep' => $directive['advance_step'],
            'newStageNumber' => $directive['new_stage_number'],
            'newStatus' => $directive['new_status'],
            'confidence' => $directive['confidence'],
        ]);

        return $directive;
    }

    /**
     * @param array<int, array<string, mixed>> $quests
     * @return array<string, mixed>
     */
    private function normalizeDirective(string $text, array $quests): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/```$/', '', $clean) ?? $clean;
        $clean = trim($clean);
        $decoded = json_decode($clean, true);

        if (! is_array($decoded)) {
            return $this->emptyDirective();
        }

        $allowedQuestIds = collect($quests)
            ->map(fn ($quest) => trim((string) ($quest['quest_id'] ?? '')))
            ->filter()
            ->values()
            ->all();

        $questId = trim((string) ($decoded['quest_id'] ?? ''));
        if ($questId !== '' && ! in_array($questId, $allowedQuestIds, true)) {
            return $this->emptyDirective();
        }

        return [
            'matched' => (bool) ($decoded['matched'] ?? false) && $questId !== '',
            'quest_id' => $questId !== '' ? $questId : null,
            'advance_step' => (bool) ($decoded['advance_step'] ?? false),
            'new_stage_number' => is_numeric($decoded['new_stage_number'] ?? null) ? (int) $decoded['new_stage_number'] : null,
            'new_status' => ($decoded['new_status'] ?? null) ? trim((string) $decoded['new_status']) : null,
            'ai_summary' => trim((string) ($decoded['ai_summary'] ?? '')),
            'directive_for_writer' => trim((string) ($decoded['directive_for_writer'] ?? '')),
            'confidence' => is_numeric($decoded['confidence'] ?? null) ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDirective(): array
    {
        return [
            'matched' => false,
            'quest_id' => null,
            'advance_step' => false,
            'new_stage_number' => null,
            'new_status' => null,
            'ai_summary' => '',
            'directive_for_writer' => '',
            'confidence' => 0.0,
        ];
    }
}
