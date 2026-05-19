<?php

namespace App\Infrastructure\Ai\Prompts;

class InterviewOpeningGeneratorPrompt
{
    /**
     * Genera el prompt de sistema para que el LLM cree una pregunta de apertura de entrevista.
     * El idioma del output se controla con el parámetro $language.
     *
     * @param array<array{field_key:string,field_label:string,hint:string}> $aiFields
     * @param string $language  "Spanish" | "English"
     */
    public static function getFallback(array $aiFields, string $language = 'Spanish'): string
    {
        $fieldsJson = json_encode(array_values($aiFields), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $languageInstructions = $language === 'English'
            ? '- Uses warm second-person ("tell me about", "you", "your")'
            : '- Uses second-person informal ("cuéntame", "tú", "te gusta")';

        return <<<PROMPT
You are a creative writer who designs warm, conversational opening questions for roleplay profile interviews.

## Your Task

Given a list of profile fields, create ONE natural opening question in {$language} that:
- Invites the user to share freely about ALL the listed topics in a single paragraph
- Sounds warm and encouraging, not like a form
{$languageInstructions}
- Does NOT list fields explicitly or use bullet points
- Is 2-3 sentences maximum
- Ends with an open invitation to share everything they want

## Profile Fields to Cover

{$fieldsJson}

## Output Format

Respond with a single JSON object, no markdown, no explanation:

{"opening_question": "The generated question in {$language}"}
PROMPT;
    }
}
