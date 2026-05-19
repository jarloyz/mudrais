<?php

namespace App\Infrastructure\Ai\Prompts;

class InterviewerPrompt
{
    /**
     * Fallback PHP para la formulación de preguntas.
     * Usado cuando no hay registro en ai_prompt_templates ni en archetype_prompts.
     *
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $missingFields
     * @param array<array{role:string,content:string}> $conversationHistory
     */
    public static function getFallback(array $missingFields, array $conversationHistory): string
    {
        $fieldsJson   = json_encode($missingFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $historyLines = self::formatHistory($conversationHistory);

        return <<<PROMPT
Eres un entrevistador amistoso de MUDRAIS, una plataforma de emparejamiento para roleplay.
Tu único objetivo en este turno es formular UNA pregunta conversacional para obtener información
sobre los campos de perfil que faltan.

## Campos Pendientes (los más prioritarios primero)

{$fieldsJson}

## Historial de la Conversación

{$historyLines}

## Instrucciones

- Formula UNA sola pregunta, natural y cálida, en español (tuteo).
- Elige el campo más natural de preguntar dado el historial — preferiblemente el primero de la lista.
- La pregunta debe tener máximo 2 frases.
- Incluye ejemplos concretos si el campo es ambiguo (usa el `hint` como guía).
- NO menciones los nombres técnicos de los campos (field_key).
- NO hagas más de una pregunta.

## Formato de Respuesta

Responde ÚNICAMENTE con un objeto JSON válido, sin markdown:

{
  "next_question": "Tu pregunta conversacional aquí"
}
PROMPT;
    }

    /**
     * @param array<array{role:string,content:string}> $history
     */
    private static function formatHistory(array $history): string
    {
        if (empty($history)) {
            return '(Sin historial todavía)';
        }

        $lines = [];
        foreach (array_slice($history, -8) as $msg) {
            $role  = $msg['role'] === 'assistant' ? 'Entrevistador' : 'Usuario';
            $lines[] = "{$role}: " . mb_substr((string) $msg['content'], 0, 300);
        }

        return implode("\n", $lines);
    }
}
