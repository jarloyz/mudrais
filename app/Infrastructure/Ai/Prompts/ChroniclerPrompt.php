<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Domain\Scene\Activity;
use App\Models\AiPromptTemplate;

final class ChroniclerPrompt
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public static function buildMessages(Activity $scene, array $context, string $generatedMd, string $mode): array
    {
        $schemaHint = <<<'JSON'
{
  "global_beats": ["Hecho clave 1", "Hecho clave 2"],
  "global_tags": ["accion", "descubrimiento"],
  "character_updates": [
    {
      "character_id": "id_del_personaje",
      "beats": ["Lo que hizo/sintio"],
      "tags": ["herido", "feliz"]
    }
  ],
  "notes": ["Observaciones internas para revision"]
}
JSON;

        $characterNotesHints = '';
        foreach (array_slice($context['characters'] ?? [], 0, 10) as $character) {
            $characterNotesHints .= "- ".($character['id'] ?? 'unknown')."\n";
        }

        $userContent = "Activity key: {$scene->id}\n" .
            "Modo: {$mode}\n\n" .
            "Activity context:\n" . self::formatContext($context) . "\n\n" .
            ($characterNotesHints !== '' ? "Personajes presentes:\n{$characterNotesHints}\n" : "") .
            "Propuesta de escena:\n{$generatedMd}\n\n" .
            "Devuelve JSON con este esquema:\n{$schemaHint}";

        return [
            [
                'role'    => 'system',
                'content' => AiPromptTemplate::getBodyOrFail('chronicler'),
            ],
            [
                'role'    => 'user',
                'content' => $userContent,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function formatContext(array $context): string
    {
        $lines = [];
        if (! empty($context['location']['name'])) {
            $lines[] = "- Locacion: {$context['location']['name']}";
        }

        $charNames = [];
        foreach (array_slice($context['characters'] ?? [], 0, 5) as $char) {
            $charNames[] = $char['name'] ?? $char['id'] ?? 'sin_nombre';
        }
        if ($charNames !== []) {
            $lines[] = "- Personajes: " . implode(', ', $charNames);
        }

        return $lines === [] ? "_(vacio)_" : implode("\n", $lines);
    }
}
