<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

final class QuestScaffolderPrompt
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function buildMessages(string $prompt): array
    {
        return [
            [
                'role'    => 'system',
                'content' => AiPromptTemplate::getBodyOrFail('quest_scaffolder'),
            ],
            [
                'role'    => 'user',
                'content' => "## Enunciado base\n{$prompt}\n\nGenera una quest basica pero utilizable para arrancar una escena con contexto.",
            ],
        ];
    }
}
