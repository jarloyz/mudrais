<?php

namespace App\Infrastructure\Agents;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CharacterProfileAgent
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key', env('GEMINI_API_KEY'));
    }

    public function extractProfile(array $documents): array
    {
        Log::channel('agents')->info('CharacterProfileAgent: extrayendo perfil de PNJ', [
            'documents_count' => count($documents)
        ]);

        // TODO: Llamada a Gemini con el JSON Schema de personaje para mapear el Markdown a JSON estandarizado

        return [
            'name' => 'Unknown',
            'bullets' => [],
            'backgrounds' => []
        ];
    }
}
