<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class ProfileTranslatorPrompt
{
    public static function getPrompt(): string
    {
        return AiPromptTemplate::getBodyOrFail('profile_translator');
    }
}
