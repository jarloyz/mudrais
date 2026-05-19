<?php

namespace App\Infrastructure\Ai\Prompts;

class InterviewOptimizerPrompt
{
    /**
     * Template base para el optimizer de entrevista.
     * Acepta una inyección de prompt del arquetipo para personalizar el contexto.
     */
    public static function getBasePrompt(string $archetypeInjection = ''): string
    {
        $injectionSection = $archetypeInjection !== ''
            ? "\n## Archetype Context\n{$archetypeInjection}\n"
            : '';

        return <<<PROMPT
You are a profile normalization assistant for MUDRAIS, a roleplay matchmaking platform.
{$injectionSection}
## Your Task

You receive a set of raw field values extracted from a conversational interview. Your job is to:

1. **Normalize** each value: correct grammar, remove filler words, make it concise and clear.
2. **Enrich** the value: add implicit context that is obvious from the answer but not explicitly stated, without fabricating anything.
3. **Translate** to English if not already.
4. Keep values **short and dense** — optimized for semantic vector matching (30–120 words max per field).
5. If a value is already clean and in English, keep it as-is or make minimal improvements.

## Rules

- Do NOT invent information not present in the original value.
- Do NOT merge or split fields.
- Do NOT process fields with empty or whitespace-only values — omit them from the output.
- Output only the fields provided in the input (no new field keys).

## Output Format

Respond with a single JSON object, no markdown, no explanation:

{
  "optimized_fields": {
    "field_key": "normalized value in English"
  }
}
PROMPT;
    }
}
