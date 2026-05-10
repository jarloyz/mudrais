<?php

namespace App\Infrastructure\Ai\Moderation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiModerationService
{
    /**
     * Comprueba si un texto infringe las políticas de contenido de OpenAI.
     * Implementa un enfoque "fail-open" para no bloquear el servicio en caso de error de la API.
     *
     * @param string $text
     * @return array{flagged: bool, categories: array}
     */
    public function check(string $text): array
    {
        if (empty(trim($text))) {
            return [
                'flagged' => false,
                'categories' => [],
            ];
        }

        try {
            $apiKey = config('services.openai.key');
            $timeout = config('services.openai.moderation_timeout', 5);

            if (empty($apiKey)) {
                Log::warning('[OpenAiModerationService@check] OPENAI_API_KEY no configurada. Saltando validación.');
                return ['flagged' => false, 'categories' => []];
            }

            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->post('https://api.openai.com/v1/moderations', [
                    'input' => $text,
                ]);

            if ($response->failed()) {
                Log::warning('[OpenAiModerationService@check] La API de OpenAI devolvió un error: ' . $response->status(), [
                    'body' => $response->body()
                ]);
                return ['flagged' => false, 'categories' => []];
            }

            $data = $response->json();
            $result = $data['results'][0] ?? null;

            if (!$result) {
                Log::warning('[OpenAiModerationService@check] Respuesta inesperada de la API de OpenAI.', [
                    'data' => $data
                ]);
                return ['flagged' => false, 'categories' => []];
            }

            return [
                'flagged' => (bool) $result['flagged'],
                'categories' => $result['categories'] ?? [],
            ];

        } catch (Throwable $e) {
            Log::warning('[OpenAiModerationService@check] Excepción durante la moderación: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            // Fail open: permitimos el paso si hay errores de infraestructura
            return [
                'flagged' => false,
                'categories' => [],
            ];
        }
    }
}
