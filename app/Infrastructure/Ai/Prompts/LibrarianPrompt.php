<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class LibrarianPrompt
{
    /**
     * @param array<string, mixed> $hotMemory
     */
    public static function buildInstruction(array $hotMemory): string
    {
        $contextString = json_encode($hotMemory, JSON_UNESCAPED_UNICODE);

        return str_replace(
            '{hot_memory_context}',
            $contextString,
            AiPromptTemplate::getBodyOrFail('librarian'),
        );
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
                    'name' => 'search_knowledge',
                    'description' => 'Busca en el conocimiento y lore del mundo (Memoria Fría).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'La consulta semántica (ej. "habilidades de combate de Elar").'
                            ]
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_inventory',
                    'description' => 'Busca en la mochila profunda del personaje un objeto útil para un propósito.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'intent' => [
                                'type' => 'string',
                                'description' => 'El propósito u objeto buscado (ej. "algo para abrir una cerradura" o "ganzúa").'
                            ]
                        ],
                        'required' => ['intent']
                    ]
                ]
            ]
        ];
    }
}
