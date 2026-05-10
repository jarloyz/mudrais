<?php

namespace App\Infrastructure\Ai\Prompts;

final class ComplexSceneWriterPrompt
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public static function buildMessages(array $context, string $userMessage, string $mode): array
    {
        $contextPacket = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            [
                'role' => 'system',
                'content' => WriterRulesPrompt::buildSystemInstruction(),
            ],
            [
                'role' => 'user',
                'content' => WriterRulesPrompt::buildOperationalRules(),
            ],
            [
                'role' => 'user',
                'content' => "## Contexto complejo\n"
                    .($contextPacket ?: '{}')
                    ."\n\n## Input operativo del usuario\n{$userMessage}\n\n## Modo\n{$mode}"
                    ."\n\n## Herramientas de Estado (IMPORTANTE)\n"
                    ."- Usa `update_character_status` si el contenido de tu narración implica cambios significativos en la salud, energía, humor o etiquetas de estado de los personajes.\n"
                    ."- Usa `emit_narrative_notes` para dejar recordatorios sobre hechos que han ocurrido y que deben persistir en el lore o futuras escenas.\n"
                    ."\nEscribe la respuesta narrativa respetando las reglas compartidas del writer y usando el contexto complejo para continuidad y estado.",
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getTools(): array
    {
        return SimpleSceneWriterPrompt::getTools();
    }
}
