<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Prompts\InterviewOptimizerPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class InterviewOptimizerAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Normaliza y enriquece los campos extraídos por el Gatekeeper.
     * Solo procesa campos con valor no vacío.
     *
     * @param array<string,string> $extractedFields  Campos crudos del Gatekeeper
     * @param ?Archetype           $archetype         Para inyectar personalidad del arquetipo
     * @param ?string              $playerId
     * @return array<string,string>                   Campos optimizados (solo los procesados)
     */
    public function optimize(
        array $extractedFields,
        ?Archetype $archetype = null,
        ?string $playerId = null,
    ): array {
        $nonEmpty = array_filter(
            $extractedFields,
            fn($v) => is_string($v) && mb_strlen(trim($v)) >= 3
        );

        if (empty($nonEmpty)) {
            Log::debug('[InterviewOptimizerAgent@optimize] Sin campos para optimizar, saltando.');
            return [];
        }

        Log::debug('[InterviewOptimizerAgent@optimize] Inicio', [
            'fields_count' => count($nonEmpty),
            'archetype_id' => $archetype?->id,
        ]);

        $systemPrompt = $this->resolveSystemPrompt($archetype);

        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'interview_optimizer');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'interview_optimizer');
        $options  = $provider ? ['_provider' => $provider] : [];

        $userContent = json_encode($nonEmpty, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        Log::info('[InterviewOptimizerAgent@optimize] Llamando AI', [
            'model'  => $model,
            'fields' => array_keys($nonEmpty),
        ]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userContent],
        ], 0.1, 600, null, null, null, $options);

        $result = $this->parseResponse($response['text'] ?? '');

        Log::info('[InterviewOptimizerAgent@optimize] Optimización completada', [
            'output_count' => count($result),
        ]);

        return $result;
    }

    private function resolveSystemPrompt(?Archetype $archetype): string
    {
        if ($archetype !== null) {
            // Prompt específico para entrevista (devuelve JSON {optimized_fields: {...}})
            $interviewPrompt = $archetype->getPromptFor('interview_optimizer');
            if ($interviewPrompt !== null) {
                Log::debug('[InterviewOptimizerAgent] Usando prompt interview_optimizer de arquetipo', ['archetype_id' => $archetype->id]);
                return $interviewPrompt;
            }
            // NOTA: el prompt 'optimizer' del arquetipo NO se usa aquí porque devuelve
            // texto plano (para ProfileOptimizerAgent). Este agente necesita JSON.
        }

        // Inyección de personalidad del arquetipo en template base
        $injection = $archetype ? ($archetype->getPromptFor('player_profile') ?? '') : '';

        if ($injection !== '') {
            Log::debug('[InterviewOptimizerAgent] Inyectando player_profile de arquetipo', ['archetype_id' => $archetype?->id]);
        }

        $phpFallback = InterviewOptimizerPrompt::getBasePrompt($injection);

        $globalTemplate = AiPromptTemplate::getBody('interview_optimizer', '');

        if ($globalTemplate !== '') {
            return $injection !== ''
                ? str_replace('{archetype_injection}', $injection, $globalTemplate)
                : $globalTemplate;
        }

        return $phpFallback;
    }

    /**
     * @return array<string,string>
     */
    private function parseResponse(string $raw): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean ?? $raw);

        $decoded = json_decode(trim($clean ?? $raw), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[InterviewOptimizerAgent@parseResponse] JSON inválido, devolviendo vacío', [
                'raw'        => mb_substr($raw, 0, 300),
                'json_error' => json_last_error_msg(),
            ]);

            return [];
        }

        $optimized = $decoded['optimized_fields'] ?? $decoded ?? [];

        if (! is_array($optimized)) {
            return [];
        }

        // Solo strings con longitud mínima
        return array_filter(
            $optimized,
            fn($v) => is_string($v) && mb_strlen(trim($v)) >= 3
        );
    }
}
