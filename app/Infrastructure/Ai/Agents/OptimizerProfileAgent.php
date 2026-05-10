<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Models\AiPromptTemplate;
use App\Domains\Matchmaking\Models\Archetype;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class OptimizerProfileAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Genera un párrafo semántico denso a partir de los datos del registro.
     * Si el Archetype tiene un prompt de optimizer en DB, lo usa inyectando los datos.
     * Si no, usa el template `player_profile_base` de ai_prompt_templates.
     *
     * @param array<string, mixed> $registrationData Todos los datos del registro (step 1 y 2)
     * @param ?Archetype           $archetype        Arquetipo de la guild (para prompt dinámico)
     * @param ?string              $playerId         Para resolver el modelo de AI configurado
     * @return array{optimized_text: string, semantic_tag_query: string}
     * @throws \RuntimeException Si el modelo no está configurado o la respuesta es vacía
     */
    public function optimize(array $registrationData, ?Archetype $archetype = null, ?string $playerId = null): array
    {
        Log::debug('[OptimizerProfileAgent@optimize] Inicio.', [
            'player_id'      => $playerId,
            'fields_count'   => count($registrationData),
            'archetype_id'   => $archetype?->id,
            'vector_name'    => $archetype?->qdrant_vector_name,
        ]);

        if (empty($registrationData)) {
            Log::warning('[OptimizerProfileAgent@optimize] registrationData vacío, devolviendo cadena vacía.');
            return ['optimized_text' => '', 'semantic_tag_query' => ''];
        }

        $systemPrompt = $this->resolveSystemPrompt($archetype);

        $jsonData = json_encode($registrationData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (str_contains($systemPrompt, '{user_soft_data_json}')) {
            $systemPrompt = str_replace('{user_soft_data_json}', $jsonData, $systemPrompt);
            $userContent = 'Optimiza mi perfil basado en las instrucciones anteriores.';
        } else {
            $userContent = $jsonData;
        }

        $model = $this->settingsResolver->resolveAgentModel($playerId, 'optimizer');

        if ($model === '') {
            Log::error('[OptimizerProfileAgent@optimize] Modelo optimizer no configurado.', ['player_id' => $playerId]);
            throw new \RuntimeException('Modelo optimizer no configurado.');
        }

        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'optimizer');
        $options  = $provider ? ['_provider' => $provider] : [];

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userContent],
        ], 0.1, 1200, null, null, null, $options);

        $optimized = trim($response['text'] ?? '');

        if ($optimized === '') {
            Log::error('[OptimizerProfileAgent@optimize] Optimizer devolvió respuesta vacía.', [
                'player_id'   => $playerId,
                'archetype_id' => $archetype?->id,
            ]);
            throw new \RuntimeException('OptimizerProfileAgent devolvió respuesta vacía.');
        }

        $decoded = json_decode($optimized, true);
        if (is_array($decoded) && isset($decoded['optimized_text_en'])) {
            $result = [
                'optimized_text'     => $decoded['optimized_text_en'],
                'semantic_tag_query' => $decoded['semantic_tag_query'] ?? '',
            ];
        } else {
            // Fallback para prompts legacy (de DB) que devuelven texto plano
            $result = [
                'optimized_text'     => $optimized,
                'semantic_tag_query' => '',
            ];
        }

        Log::info('[OptimizerProfileAgent@optimize] Texto optimizado generado.', [
            'player_id'                  => $playerId,
            'output_chars'               => mb_strlen($result['optimized_text']),
            'semantic_tag_query_length' => mb_strlen($result['semantic_tag_query']),
            'prompt_source'              => $archetype ? 'db' : 'hardcoded',
        ]);

        return $result;
    }

    private function resolveSystemPrompt(?Archetype $archetype): string
    {
        if ($archetype !== null) {
            // Modo legacy: prompt 'optimizer' reemplaza el template completo
            $legacyPrompt = $archetype->getPromptFor('optimizer');
            if ($legacyPrompt !== null) {
                Log::debug('[OptimizerProfileAgent] Usando prompt legacy optimizer.', ['archetype_id' => $archetype->id]);
                return $legacyPrompt;
            }
        }

        $template  = AiPromptTemplate::getBodyOrFail('player_profile_base');
        $injection = $archetype ? ($archetype->getPromptFor('player_profile') ?? '') : '';

        if ($injection !== '') {
            Log::debug('[OptimizerProfileAgent] Inyectando prompt player_profile.', ['archetype_id' => $archetype?->id]);
        } else {
            Log::debug('[OptimizerProfileAgent] Sin prompt por arquetipo, usando template base.');
        }

        return str_replace('{archetype_prompt_injection}', $injection, $template);
    }
}
