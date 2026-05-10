<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

final class WriterRulesPrompt
{
    public static function buildSystemInstruction(): string
    {
        return AiPromptTemplate::getBodyOrFail('writer_rules_system');
    }

    public static function buildOperationalRules(): string
    {
        return AiPromptTemplate::getBodyOrFail('writer_rules_operational');
    }
}
