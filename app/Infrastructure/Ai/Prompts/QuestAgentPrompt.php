<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Domain\Scene\Activity;
use App\Models\AiPromptTemplate;

final class QuestAgentPrompt
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, string>>
     */
    public static function buildMessages(Activity $scene, array $context, string $userMessage): array
    {
        $questsJson = json_encode($context['quests'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $sceneJson = json_encode([
            'scene_id'    => $scene->id,
            'title'       => $scene->title,
            'objective'   => $scene->objective,
            'constraints' => $scene->constraints,
            'location'    => $context['location'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return [
            [
                'role'    => 'system',
                'content' => AiPromptTemplate::getBodyOrFail('quest_agent'),
            ],
            [
                'role'    => 'user',
                'content' => "## Escena\n{$sceneJson}\n\n## Quests activas\n{$questsJson}\n\n## Accion del usuario\n{$userMessage}\n\nEvalua si la accion resuelve o modifica de forma clara alguna quest activa.",
            ],
        ];
    }
}
