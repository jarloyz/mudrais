<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Domain\Scene\Activity;

final class SimpleSceneWriterPrompt
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $writerConfig
     * @return array<int, array<string, mixed>>
     */
    public static function buildMessages(Activity $scene, array $context, string $userMessage, string $mode, array $writerConfig = []): array
    {
        $sections = SimpleScenePrompt::buildSections(
            scene: $scene,
            context: $context,
        );

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
                'content' => self::buildRuntimeInstruction(
                    stableContext: $sections['stableContext'],
                    dynamicContext: $sections['dynamicContext'],
                    userMessage: $userMessage,
                    mode: $mode,
                    writerConfig: $writerConfig,
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $writerConfig
     */
    private static function buildRuntimeInstruction(string $stableContext, string $dynamicContext, string $userMessage, string $mode, array $writerConfig = []): string
    {
        $styleProfile = trim((string) ($writerConfig['style_profile'] ?? 'cinematico'));
        $styleNotes = trim((string) ($writerConfig['style_notes'] ?? ''));
        $responseLength = trim((string) ($writerConfig['response_length'] ?? 'medio'));

        return $stableContext
            ."\n\n"
            .$dynamicContext
            ."\n\n## Input operativo del usuario\n"
            .$userMessage
            ."\n\n## Modo\n"
            .$mode
            ."\n\n## Perfil de estilo\n"
            .self::renderStyleProfile($styleProfile)
            .($styleNotes !== '' ? "\nNotas extra de estilo: {$styleNotes}" : '')
            ."\n\n## Longitud deseada\n"
            .self::renderResponseLength($responseLength)
            ."\n\n## Herramientas de Estado (IMPORTANTE)\n"
            ."- Usa `update_character_status` si el contenido de tu narración implica cambios significativos en la salud, energía, humor o etiquetas de estado de los personajes.\n"
            ."- Usa `emit_narrative_notes` para dejar recordatorios sobre hechos que han ocurrido y que deben persistir en el lore o futuras escenas.\n"
            ."\nEscribe la continuacion narrativa respetando las reglas compartidas del writer y el contexto simple actual.";
    }

    private static function renderStyleProfile(string $styleProfile): string
    {
        return match ($styleProfile) {
            'sobrio' => 'Prosa sobria, precisa y contenida. Prioriza claridad, subtexto y control del ritmo.',
            'intimo' => 'Prosa cercana e intima. Prioriza pequeños gestos, respiracion emocional y tension interpersonal.',
            'sensorial' => 'Prosa sensorial. Prioriza atmosfera, percepcion, tacto, sonido y detalles del entorno.',
            'rapido' => 'Prosa agil y directa. Prioriza respuesta inmediata, dinamismo y avance claro de la accion.',
            'oscuro' => 'Prosa densa y ominosa. Prioriza amenaza, incomodidad, ambiguedad y peso del ambiente.',
            'romantico' => 'Prosa emocional y cargada de tension afectiva. Prioriza miradas, subtexto, cercania y deseo contenido.',
            default => 'Prosa cinematica. Prioriza imagen clara, ritmo visual, ambiente y reaccion de NPC/mundo.',
        };
    }

    private static function renderResponseLength(string $responseLength): string
    {
        return match ($responseLength) {
            'corto' => 'Respuesta breve: 1 a 3 parrafos cortos, solo lo necesario para reaccion inmediata.',
            'largo' => 'Respuesta amplia: 4 a 7 parrafos con mas ambiente, reaccion y detalle de NPC/mundo.',
            default => 'Respuesta media: 2 a 4 parrafos con buen equilibrio entre avance, ambiente y reaccion.',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_character_status',
                    'description' => 'Actualiza el estado físico, emocional o situacional de uno o varios personajes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'changes' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'character_name' => [
                                            'type' => 'string',
                                            'description' => 'Nombre del personaje tal cual aparece en el contexto.',
                                        ],
                                        'health' => [
                                            'type' => 'integer',
                                            'description' => 'Nuevo valor de salud (0-100). Solo si cambia.',
                                        ],
                                        'stamina' => [
                                            'type' => 'integer',
                                            'description' => 'Nuevo valor de energía/estamina (0-100). Solo si cambia.',
                                        ],
                                        'status_tags' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'description' => 'Lista completa de etiquetas de estado (ej: ["herido", "cansado", "ebrio"]).',
                                        ],
                                        'mood' => [
                                            'type' => 'string',
                                            'description' => 'Breve descripción del humor o estado emocional actual.',
                                        ],
                                    ],
                                    'required' => ['character_name'],
                                ],
                            ],
                        ],
                        'required' => ['changes'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'emit_narrative_notes',
                    'description' => 'Registra observaciones técnicas o recordatorios para futuras escenas (meta-datos).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'notes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Lista de notas breves.',
                            ],
                        ],
                        'required' => ['notes'],
                    ],
                ],
            ],
        ];
    }
}
