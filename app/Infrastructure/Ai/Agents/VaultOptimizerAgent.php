<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Models\AiPromptTemplate;
use App\Domains\Matchmaking\Models\Archetype;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class VaultOptimizerAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * @return array{name_es: string, name_en: string, optimized_text_en: string, semantic_tag_query: string}
     */
    public function optimize(string $inputName, string $inputDescription, ?Archetype $archetype = null): array
    {
        Log::debug('[VaultOptimizerAgent] Iniciando optimización de vault', [
            'input_name'   => $inputName,
            'input_chars'  => mb_strlen($inputDescription),
            'archetype_id' => $archetype?->id,
        ]);

        $optimizerModel = $this->settingsResolver->resolveAgentModel(null, 'optimizer');

        if ($optimizerModel === '') {
            Log::error('[VaultOptimizerAgent] Modelo optimizer no configurado — imposible optimizar');
            throw new \RuntimeException('Modelo optimizer no configurado.');
        }

        $provider = $this->settingsResolver->resolveAgentProvider(null, 'optimizer');
        $options  = $provider ? ['_provider' => $provider] : [];

        $systemPrompt = $this->resolveSystemPrompt($archetype);
        $inputJson    = json_encode(['name' => $inputName, 'description' => $inputDescription], JSON_UNESCAPED_UNICODE);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $inputJson],
        ];

        $structured = null;
        $maxAttempts = 2;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->gateway->chat($optimizerModel, $messages, 0.2, 800, null, null, null, $options);

            $rawJson = trim($response['text'] ?? '');
            $rawJson = preg_replace('/^```(?:json)?\s*/i', '', $rawJson) ?? $rawJson;
            $rawJson = preg_replace('/```\s*$/i', '', $rawJson) ?? $rawJson;
            $rawJson = trim($rawJson);

            if ($rawJson === '') {
                Log::warning('[VaultOptimizerAgent] Respuesta vacía del LLM', [
                    'input_name' => $inputName,
                    'attempt'    => $attempt,
                ]);
                continue;
            }

            $decoded = json_decode($rawJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('[VaultOptimizerAgent] Error parseando JSON de respuesta', [
                    'input_name'   => $inputName,
                    'attempt'      => $attempt,
                    'raw_response' => mb_substr($rawJson, 0, 300),
                    'json_error'   => json_last_error_msg(),
                ]);
                continue;
            }

            $missingFields = array_filter(
                ['name_es', 'name_en', 'optimized_text_en', 'semantic_tag_query'],
                fn($f) => empty($decoded[$f])
            );

            if (empty($missingFields)) {
                $structured = $decoded;
                break;
            }

            // Si solo falta semantic_tag_query (índice 3) pero tenemos optimized_text_en, derivamos un fallback
            if (count($missingFields) === 1 && isset($missingFields[3])) {
                if (!empty($decoded['optimized_text_en'])) {
                    $decoded['semantic_tag_query'] = $this->deriveTagQuery($decoded['optimized_text_en']);
                    Log::warning('[VaultOptimizerAgent] semantic_tag_query ausente — usando fallback derivado de optimized_text_en', [
                        'input_name' => $inputName,
                        'attempt'    => $attempt,
                        'fallback'   => $decoded['semantic_tag_query'],
                    ]);
                    $structured = $decoded;
                    break;
                }
            }

            Log::warning('[VaultOptimizerAgent] JSON incompleto, reintentando', [
                'input_name'     => $inputName,
                'attempt'        => $attempt,
                'missing_fields' => array_values($missingFields),
            ]);
        }

        if ($structured === null) {
            Log::error('[VaultOptimizerAgent] JSON de respuesta incompleto tras todos los intentos', [
                'input_name' => $inputName,
            ]);
            throw new \RuntimeException('JSON de respuesta incompleto tras múltiples intentos.');
        }

        Log::debug('[VaultOptimizerAgent] Optimización exitosa', [
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

    private function deriveTagQuery(string $optimizedText): string
    {
        $phrases = [];
        foreach (explode('|', $optimizedText) as $segment) {
            // Strip label (e.g. "TECH_STACK: ") and split by comma
            $content = preg_replace('/^[A-Z_]+:\s*/u', '', trim($segment));
            foreach (explode(',', $content) as $phrase) {
                $phrase = trim($phrase);
                if ($phrase !== '') {
                    $phrases[] = $phrase;
                }
            }
        }

        return implode(', ', array_slice($phrases, 0, 20));
    }

    private function resolveSystemPrompt(?Archetype $archetype): string
    {
        $template  = AiPromptTemplate::getBodyOrFail('vault_base');
        $injection = $archetype ? ($archetype->getPromptFor('vault') ?? '') : '';

        if ($injection !== '') {
            Log::debug('[VaultOptimizerAgent] Inyectando prompt vault por arquetipo.', ['archetype_id' => $archetype?->id]);
        } else {
            Log::debug('[VaultOptimizerAgent] Sin prompt por arquetipo, usando template base.');
        }

        return str_replace('{archetype_prompt_injection}', $injection, $template);
    }
}
