<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Domain\Scene\Activity;
use App\Models\AiPromptTemplate;
use App\Support\LogPreview;

class SimpleLibrarianPrompt
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $parameters
     * @return array<int, array<string, mixed>>
     */
    public static function buildMessages(Activity $scene, array $context, string $userMessage, array $parameters): array
    {
        $system = AiPromptTemplate::getBodyOrFail('simple_librarian');

        $contextSummary = LogPreview::json([
            'location' => $context['location'] ?? 'Desconocida',
            'characters' => array_map(fn($c) => $c['name'] ?? 'NPC', (array)($context['characters'] ?? [])),
            'rolling_summary' => $context['rolling_summary'] ?? '',
        ], 2000);

        $prompt = "CONTEXTO ACTUAL:\n{$contextSummary}\n\n";
        $prompt .= "ESCENA ACTUAL (Objetivo):\n{$scene->objective}\n\n";
        $prompt .= "MENSAJE DEL USUARIO:\n{$userMessage}\n\n";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ];
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
                    'name' => 'search_knowledge_base',
                    'description' => 'Busca información específica en la base de conocimientos (lore/RAG) del mundo.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'El término, frase o pregunta de búsqueda optimizada para similitud de embeddings.',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Breve razón técnica de por qué se necesita esta información.',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }
}
