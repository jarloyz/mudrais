<?php

namespace App\Infrastructure\Ai\Prompts;

class VoiceGatekeeperPrompt
{
    /**
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string,field_type:string,options:array}> $fields
     * @param array<string,string> $alreadyExtracted
     * @param string $lastQuestion  La última pregunta hecha al usuario (contexto crítico para la extracción).
     */
    public static function getFallback(array $fields, array $alreadyExtracted, string $lastQuestion = ''): string
    {
        $fieldsJson    = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $extractedJson = json_encode($alreadyExtracted, JSON_UNESCAPED_UNICODE);
        $contextBlock  = $lastQuestion !== ''
            ? "## Last Question Asked (CRITICAL context — use this to map the answer to the correct field)\n\n\"{$lastQuestion}\"\n\nThe user is answering THIS question. Map their response to the field this question was about."
            : '## Last Question Asked\n\n(No prior question — this may be the first response.)';

        return <<<PROMPT
You are processing a voice interview transcript for MUDRAIS, a roleplay matchmaking platform.
The user may speak in Spanish, English, or a mix of both — process whatever language is used.

Your job:
1. Classify whether the user provided relevant information.
2. Extract field values by interpreting the answer IN THE CONTEXT OF THE LAST QUESTION.

{$contextBlock}

## Profile Fields to Collect

{$fieldsJson}

## Already Collected (skip these unless user is correcting them)

{$extractedJson}

## Field Type Extraction Rules

- text / text_short / text_long: extract the user's words, paraphrased into a clean sentence.
- select: normalize the spoken answer to EXACTLY one value from the field's options array.
  Example: "kind of a newbie" → "beginner" (when options are beginner/intermediate/expert).
- multiselect: extract ALL matching options as a single comma-separated string.
  Example: "fantasy and horror" → "fantasy, horror" (when those are valid options).
- range: extract the numeric value mentioned (or the closest integer in the valid range).
- boolean: normalize to exactly "yes" or "no".

## IMPORTANT: Context-First Extraction

The last question is your primary signal for which field to extract.
Example: if the question was about absolute limits, and the user says "romantic roleplay",
extract that as "red_lines", NOT as "preferences" — even if the content sounds like a preference.

Only map to a different field if the user explicitly changes topic ("actually, about my style...").

## Classification

- "answer": the user is providing information relevant to any of the profile fields.
- "off_topic": the user said something completely unrelated to their roleplay profile.

## Output

Respond with a single JSON object. Only include fields in "extracted" that you are confident about:

{
  "response_type": "answer",
  "extracted": {
    "field_key_1": "extracted value"
  }
}
PROMPT;
    }
}
