<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class ProfileChunkerPrompt
{
    public static function getSystemPrompt(): string
    {
        return AiPromptTemplate::getBodyOrFail('profile_chunker');
    }
}
