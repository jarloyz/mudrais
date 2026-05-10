<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class ContentSafetyPrompt
{
    public static function getPrompt(): string
    {
        return AiPromptTemplate::getBodyOrFail('content_safety');
    }
}
