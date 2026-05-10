<?php

namespace App\Infrastructure\Agents;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SqlMigrationAgent
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key', env('GEMINI_API_KEY'));
    }

    public function generatePlan(string $markdownContent): array
    {
        Log::channel('agents')->info('SqlMigrationAgent: analizando legacy vault');

        // TODO: Llamada a Gemini para transformar el vault entero en sentencias o JSON SQL

        return [
            'activities' => [],
            'events' => [],
            'characters' => []
        ];
    }
}
