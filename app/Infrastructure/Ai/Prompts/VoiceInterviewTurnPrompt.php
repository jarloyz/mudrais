<?php

namespace App\Infrastructure\Ai\Prompts;

class VoiceInterviewTurnPrompt
{
    /**
     * Prompt unificado que hace en UN SOLO viaje lo que antes hacían dos agentes:
     *  - VoiceGatekeeperAgent: extrae valores de campos del transcript.
     *  - VoiceInterviewerAgent: genera la siguiente pregunta hablada.
     *
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string,field_type:string,options:array}> $allFields
     * @param array<string,string> $alreadyExtracted
     * @param string $lastQuestion   Última pregunta del entrevistador (contexto crítico de extracción).
     * @param array<array{role:string,content:string}> $conversationHistory
     */
    public static function getFallback(
        array $allFields,
        array $alreadyExtracted,
        string $lastQuestion,
        array $conversationHistory,
    ): string {
        $fieldsBlock   = self::formatFields($allFields, $alreadyExtracted);
        $extractedJson = json_encode($alreadyExtracted, JSON_UNESCAPED_UNICODE);
        $historyBlock  = self::formatHistory($conversationHistory);
        $contextLine   = $lastQuestion !== ''
            ? "\"{$lastQuestion}\""
            : '(First turn — no prior question)';

        return <<<PROMPT
You are a voice interviewer for MUDRAIS, a roleplay matchmaking platform.
You handle a real-time voice conversation. The user may speak Spanish, English, or a mix of both.

In ONE response you must do TWO things:
  1. EXTRACT — map what the user said to the correct profile fields.
  2. GENERATE — write the next spoken question for any remaining required fields.

## Last Question Asked (primary extraction context)

{$contextLine}

The user's answer must be interpreted in the context of this question.
If the question was about limits, map the answer to "red_lines" — not "preferences".

## Conversation History

{$historyBlock}

## Profile Fields

{$fieldsBlock}

## Already Collected

{$extractedJson}

---

### EXTRACTION RULES

- text / text_short / text_long → paraphrase into a clean sentence.
- select → normalize to EXACTLY one value from the field's options list.
  Example: "kind of new" → "beginner" (options: beginner/intermediate/expert).
- multiselect → comma-separated matching options from the list.
- range → the numeric value mentioned, or the nearest integer in the valid range.
- boolean → exactly "yes" or "no".

Use the last question as the primary signal for which field the user is answering.
Only map to a different field if the user explicitly switches topics.

### NEXT QUESTION RULES

Assume the fields in "extracted" are now collected. Look at what required fields would still be missing.
- If required fields remain: write ONE short spoken English question (≤ 30 words, no markdown, no emojis).
  - select / multiselect → name the options: "You can say: A, B, or C."
  - range → describe the scale: "On a scale from 1 to 10…"
  - boolean → name both choices.
  - text → open question with a concrete example from the field hint.
- If all required fields would be covered → set "next_question" to null.

---

## Output — valid JSON only, no markdown fences

{
  "response_type": "answer",
  "extracted": {
    "field_key": "value"
  },
  "next_question": "Your spoken English question here"
}

For off-topic responses: {"response_type": "off_topic", "extracted": {}, "next_question": null}
PROMPT;
    }

    /**
     * @param array<array{field_key:string,...}> $allFields
     * @param array<string,string> $alreadyExtracted
     */
    private static function formatFields(array $allFields, array $alreadyExtracted): string
    {
        $lines = [];
        foreach ($allFields as $f) {
            $collected = isset($alreadyExtracted[$f['field_key']]) ? '✓ collected' : (($f['is_required'] ?? false) ? '★ required' : 'optional');
            $fieldType = $f['field_type'] ?? 'text';
            $line      = "[{$collected}] {$f['field_key']} — \"{$f['field_label']}\"";

            if ($f['hint'] ?? '') {
                $line .= " (hint: {$f['hint']})";
            }

            if (in_array($fieldType, ['select', 'multiselect'], true) && ! empty($f['options'])) {
                $opts  = array_map(fn($o) => is_array($o) ? ($o['label'] ?? $o['value'] ?? '') : (string) $o, $f['options']);
                $line .= " | options: [" . implode(', ', $opts) . "]";
            } elseif ($fieldType === 'range' && is_array($f['options'] ?? null)) {
                $line .= " | scale: " . ($f['options']['min'] ?? 1) . "–" . ($f['options']['max'] ?? 10);
            } elseif ($fieldType === 'boolean') {
                $line .= " | options: [yes, no]";
            }

            $lines[] = $line;
        }

        return empty($lines) ? '(no fields defined)' : implode("\n", $lines);
    }

    /**
     * @param array<array{role:string,content:string}> $history
     */
    private static function formatHistory(array $history): string
    {
        if (empty($history)) {
            return '(No prior turns)';
        }

        $lines = [];
        foreach (array_slice($history, -6) as $msg) {
            $role    = $msg['role'] === 'assistant' ? 'Interviewer' : 'User';
            $lines[] = "{$role}: " . mb_substr((string) $msg['content'], 0, 200);
        }

        return implode("\n", $lines);
    }
}
