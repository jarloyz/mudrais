<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

final class SummarizerPrompt
{
    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @return array<int, array<string, mixed>>
     */
    public static function buildIncrementalMessages(string $sceneId, string $existingSummary, array $messages): array
    {
        $lines = [];

        foreach ($messages as $message) {
            $role = trim((string) ($message['role'] ?? ''));
            $content = trim((string) ($message['content'] ?? ''));

            if ($role === '' || $content === '') {
                continue;
            }

            $lines[] = '- '.($role === 'assistant' ? 'asistente' : 'usuario').': '.self::clip($content, 600);
        }

        $existingSummary = trim($existingSummary);

        return [
            [
                'role'    => 'system',
                'content' => AiPromptTemplate::getBodyOrFail('summarizer'),
            ],
            [
                'role'    => 'user',
                'content' => "Activity key: {$sceneId}\n\n"
                    ."## Resumen acumulado actual\n"
                    .($existingSummary !== '' ? $existingSummary : '_(vacio)_')
                    ."\n\n## Nuevos mensajes sin resumir\n"
                    .($lines !== [] ? implode("\n", $lines) : '- _(sin mensajes)_')
                    ."\n\nDevuelve el NUEVO resumen acumulado consolidado. "
                    .'Maximo 12 bullets. Maximo 1200 caracteres.',
            ],
        ];
    }

    private static function clip(string $value, int $maxChars): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $maxChars - 3))).'...';
    }
}
