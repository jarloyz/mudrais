<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class ArchetypeOptimizerAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * @return array{name_es: string, name_en: string, optimized_text_en: string, semantic_tag_query: string}
     */
    public function optimize(string $inputName, string $inputText): array
    {
        Log::debug('[ArchetypeOptimizerAgent] Iniciando optimización de arquetipo', [
            'input_name' => $inputName,
            'input_chars' => mb_strlen($inputText),
        ]);

        $optimizerModel = $this->settingsResolver->resolveAgentModel(null, 'optimizer');

        if ($optimizerModel === '') {
            Log::error('[ArchetypeOptimizerAgent] Modelo optimizer no configurado — imposible optimizar');
            throw new \RuntimeException('Modelo optimizer no configurado.');
        }

        $provider = $this->settingsResolver->resolveAgentProvider(null, 'optimizer');
        $options  = $provider ? ['_provider' => $provider] : [];

        $systemPrompt = AiPromptTemplate::getBodyOrFail('archetype_base');
        $inputJson    = json_encode(['name' => $inputName, 'text' => $inputText], JSON_UNESCAPED_UNICODE);

        $response = $this->gateway->chat($optimizerModel, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $inputJson],
        ], 0.2, 1500, null, null, null, $options);

        $rawJson = trim($response['text'] ?? '');
        $rawJson = preg_replace('/^```(?:json)?\s*/i', '', $rawJson) ?? $rawJson;
        $rawJson = preg_replace('/```\s*$/i', '', $rawJson) ?? $rawJson;
        $rawJson = trim($rawJson);

        if ($rawJson === '') {
            Log::error('[ArchetypeOptimizerAgent] Respuesta vacía del LLM al optimizar arquetipo', [
                'input_name' => $inputName,
            ]);
            throw new \RuntimeException('Respuesta vacía del LLM al optimizar arquetipo.');
        }

        $structured = json_decode($rawJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('[ArchetypeOptimizerAgent] Error parseando JSON de respuesta', [
                'input_name' => $inputName,
                'raw_response' => $rawJson,
                'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('Error parseando respuesta JSON del LLM.');
        }

        if (
            empty($structured['name_es']) ||
            empty($structured['name_en']) ||
            empty($structured['optimized_text_en']) ||
            empty($structured['semantic_tag_query'])
        ) {
            Log::error('[ArchetypeOptimizerAgent] JSON de respuesta no contiene los campos requeridos', [
                'input_name' => $inputName,
                'structured' => $structured,
            ]);
            throw new \RuntimeException('JSON de respuesta incompleto.');
        }

        Log::debug('[ArchetypeOptimizerAgent] Optimización exitosa', [
            'name_es'            => $structured['name_es'],
            'name_en'            => $structured['name_en'],
            'tag_query_preview'  => mb_substr($structured['semantic_tag_query'], 0, 80),
        ]);

        return [
            'name_es'            => $structured['name_es'],
            'name_en'            => $structured['name_en'],
            'optimized_text_en'  => $structured['optimized_text_en'],
            'semantic_tag_query' => $structured['semantic_tag_query'],
        ];
    }
}
