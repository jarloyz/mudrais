<?php

namespace App\Infrastructure\Ai\Prompts;

class VoiceInterviewerPrompt
{
    /**
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string,field_type:string,options:array}> $missingFields
     * @param array<array{role:string,content:string}> $conversationHistory
     */
    public static function getFallback(array $missingFields, array $conversationHistory): string
    {
        $fieldList    = self::formatFields($missingFields);
        $historyLines = self::formatHistory($conversationHistory);

        return <<<PROMPT
You are a friendly interviewer for MUDRAIS, a roleplay matchmaking platform.
This is a VOICE interview — the user hears audio only, they cannot see text.

## Missing Profile Fields (ask in this order, required first)

{$fieldList}

## Recent Conversation

{$historyLines}

## Rules

- Ask exactly ONE question, short and natural (≤ 30 words).
- Plain English only — no markdown, no asterisks, no emojis, no line breaks.
- For SELECT or MULTISELECT fields: naturally name the available options ("You can say: beginner, intermediate, or expert").
- For RANGE fields: describe the scale ("On a scale from 1 to 10").
- For BOOLEAN fields: present both choices ("Would you say yes or no?").
- For TEXT fields: ask openly and give a brief concrete example from the hint.
- Prioritize required fields. Ask the topmost one unless the conversation naturally flows elsewhere.
- Never ask more than one question. Never mention technical field names.

## Response Format

{"next_question": "Your spoken English question here"}
PROMPT;
    }

    /**
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string,field_type:string,options:array}> $fields
     */
    private static function formatFields(array $fields): string
    {
        if (empty($fields)) {
            return '(all fields collected)';
        }

        $lines = [];
        foreach ($fields as $f) {
            $required  = $f['is_required'] ? '★ required' : 'optional';
            $fieldType = $f['field_type'] ?? 'text';
            $line      = "[{$required}] {$f['field_key']} — \"{$f['field_label']}\"";

            if ($f['hint'] ?? '') {
                $line .= " (hint: {$f['hint']})";
            }

            if (in_array($fieldType, ['select', 'multiselect'], true) && ! empty($f['options'])) {
                $options = array_map(function ($o) {
                    return is_array($o) ? ($o['label'] ?? $o['value'] ?? json_encode($o)) : (string) $o;
                }, $f['options']);
                $line .= ' | options: [' . implode(', ', $options) . ']';
            } elseif ($fieldType === 'range' && is_array($f['options'] ?? null)) {
                $min  = $f['options']['min'] ?? 1;
                $max  = $f['options']['max'] ?? 10;
                $line .= " | scale: {$min}–{$max}";
            } elseif ($fieldType === 'boolean') {
                $line .= ' | options: [yes, no]';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array{role:string,content:string}> $history
     */
    private static function formatHistory(array $history): string
    {
        if (empty($history)) {
            return '(No history yet)';
        }

        $lines = [];
        foreach (array_slice($history, -6) as $msg) {
            $role    = $msg['role'] === 'assistant' ? 'Interviewer' : 'User';
            $lines[] = "{$role}: " . mb_substr((string) $msg['content'], 0, 200);
        }

        return implode("\n", $lines);
    }
}
