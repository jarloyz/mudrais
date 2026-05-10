<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class StyleGatekeeperPrompt
{
    public static function getPrompt(): string
    {
        return AiPromptTemplate::getBodyOrFail('style_gatekeeper');
    }
}
