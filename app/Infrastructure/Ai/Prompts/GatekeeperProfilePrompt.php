<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class GatekeeperProfilePrompt
{
    public static function getFallbackPrompt(): string
    {
        return AiPromptTemplate::getBodyOrFail('gatekeeper_profile');
    }
}
