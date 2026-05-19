<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Infrastructure\Ai\Prompts\StyleGatekeeperPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class ProfileOptimizerAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
        private ArchetypeMutatorService $mutatorService,
    ) {}

    /**
     * Generates a dense semantic paragraph for vector embedding.
     *
     * @param  string|array  $input
     *   - array:  structured registration data (Step2 path — gatekeeper skipped, data already clean)
     *   - string: raw text (re-index / avatar / activity path — gatekeeper runs first)
     * @return array{optimized_text: string, semantic_tag_query: string}
     */
    public function optimize(
        string|array $input,
        ?Archetype $archetype = null,
        ?string $playerId = null,
    ): array {
        Log::debug('[ProfileOptimizerAgent@optimize] Inicio', [
            'player_id'    => $playerId,
            'archetype_id' => $archetype?->id,
            'input_type'   => is_array($input) ? 'array' : 'string',
            'input_size'   => is_array($input) ? count($input) : mb_strlen((string) $input),
        ]);

        if (is_array($input)) {
            return $this->optimizeFromArray($input, $archetype, $playerId);
        }

        return $this->optimizeFromText((string) $input, $archetype, $playerId);
    }

    // ── Flujos de entrada ────────────────────────────────────────────────────

    private function optimizeFromArray(array $data, ?Archetype $archetype, ?string $playerId): array
    {
        if (empty($data)) {
            Log::warning('[ProfileOptimizerAgent] Array vacío — devolviendo vacío.');
            return ['optimized_text' => '', 'semantic_tag_query' => ''];
        }

        $systemPrompt = $this->resolveOptimizerPrompt($archetype);
        $jsonData     = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (str_contains($systemPrompt, '{user_soft_data_json}')) {
            $systemPrompt = str_replace('{user_soft_data_json}', $jsonData, $systemPrompt);
            $userContent  = 'Optimiza mi perfil basado en las instrucciones anteriores.';
        } else {
            $userContent = $jsonData;
        }

        return $this->callOptimizer($systemPrompt, $userContent, $archetype, $playerId);
    }

    private function optimizeFromText(string $rawText, ?Archetype $archetype, ?string $playerId): array
    {
        $rawText = trim($rawText);

        if ($rawText === '') {
            Log::warning('[ProfileOptimizerAgent] Texto vacío — devolviendo vacío.');
            return ['optimized_text' => '', 'semantic_tag_query' => ''];
        }

        $positives    = $this->runGatekeeper($rawText, $archetype, $playerId);
        $systemPrompt = $this->resolveOptimizerPrompt($archetype);
        $userContent  = json_encode($positives, JSON_UNESCAPED_UNICODE);

        return $this->callOptimizer($systemPrompt, $userContent, $archetype, $playerId);
    }

    // ── Pasos internos ───────────────────────────────────────────────────────

    /** @return list<string> */
    private function runGatekeeper(string $rawText, ?Archetype $archetype, ?string $playerId): array
    {
        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'gatekeeper');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'gatekeeper');
        $options  = $provider ? ['_provider' => $provider] : [];

        if ($model === '') {
            Log::error('[ProfileOptimizerAgent] Modelo gatekeeper no configurado.', ['player_id' => $playerId]);
            throw new \RuntimeException('Modelo gatekeeper no configurado.');
        }

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $this->resolveGatekeeperPrompt($archetype)],
            ['role' => 'user',   'content' => $rawText],
        ], 0.0, 1200, null, null, null, $options);

        $rawJson = trim($response['text'] ?? '');
        $rawJson = preg_replace('/^```(?:json)?\s*/i', '', $rawJson) ?? $rawJson;
        $rawJson = preg_replace('/```\s*$/i',          '', $rawJson) ?? $rawJson;
        $rawJson = trim($rawJson);

        $structured = json_decode($rawJson, true);
        $positives  = is_array($structured['positives'] ?? null) ? $structured['positives'] : [];

        if (empty($positives)) {
            Log::warning('[ProfileOptimizerAgent] Gatekeeper no extrajo facts — texto insuficiente.', [
                'player_id' => $playerId,
                'raw_json'  => mb_substr($rawJson, 0, 300),
            ]);
            throw new \RuntimeException('Gatekeeper no pudo extraer facts del perfil.');
        }

        Log::debug('[ProfileOptimizerAgent] Facts extraídos por gatekeeper.', [
            'player_id'    => $playerId,
            'positives'    => count($positives),
            'red_lines'    => count($structured['red_lines']    ?? []),
            'yellow_lines' => count($structured['yellow_lines'] ?? []),
        ]);

        return $positives;
    }

    /** @return array{optimized_text: string, semantic_tag_query: string} */
    private function callOptimizer(string $systemPrompt, string $userContent, ?Archetype $archetype, ?string $playerId): array
    {
        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'optimizer');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'optimizer');
        $options  = $provider ? ['_provider' => $provider] : [];

        if ($model === '') {
            Log::error('[ProfileOptimizerAgent] Modelo optimizer no configurado.', [
                'player_id'    => $playerId,
                'archetype_id' => $archetype?->id,
            ]);
            throw new \RuntimeException('Modelo optimizer no configurado.');
        }

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userContent],
        ], 0.1, 1200, null, null, null, $options);

        $optimized = trim($response['text'] ?? '');

        if ($optimized === '') {
            Log::error('[ProfileOptimizerAgent] Optimizer devolvió respuesta vacía.', [
                'player_id'    => $playerId,
                'archetype_id' => $archetype?->id,
            ]);
            throw new \RuntimeException('ProfileOptimizerAgent devolvió respuesta vacía.');
        }

        $decoded = json_decode($optimized, true);
        if (is_array($decoded) && isset($decoded['optimized_text_en'])) {
            $result = [
                'optimized_text'     => $decoded['optimized_text_en'],
                'semantic_tag_query' => $decoded['semantic_tag_query'] ?? '',
            ];
        } else {
            $result = [
                'optimized_text'     => $optimized,
                'semantic_tag_query' => '',
            ];
        }

        Log::info('[ProfileOptimizerAgent] Texto optimizado generado.', [
            'player_id'                 => $playerId,
            'archetype_id'              => $archetype?->id,
            'output_chars'              => mb_strlen($result['optimized_text']),
            'semantic_tag_query_length' => mb_strlen($result['semantic_tag_query']),
        ]);

        return $result;
    }

    // ── Resolución de prompts ────────────────────────────────────────────────

    private function resolveGatekeeperPrompt(?Archetype $archetype): string
    {
        if ($archetype === null) {
            Log::debug('[ProfileOptimizerAgent] Sin arquetipo — usando style_gatekeeper genérico.');
            return StyleGatekeeperPrompt::getPrompt();
        }

        // 'style_gatekeeper' is a distinct key from 'gatekeeper' (the registration form extractor).
        $custom = $archetype->getPromptFor('style_gatekeeper');
        if ($custom !== null) {
            Log::debug('[ProfileOptimizerAgent] Usando style_gatekeeper personalizado del arquetipo.', [
                'archetype_id' => $archetype->id,
            ]);
            return $custom;
        }

        $mutators = $this->mutatorService
            ->getFieldsForContext($archetype->id, 'registration')
            ->filter(fn ($m) => $m->storage_mode->storesSemantic());

        $base = StyleGatekeeperPrompt::getPrompt();

        if ($mutators->isEmpty()) {
            return $base;
        }

        $fieldLines = $mutators
            ->map(fn ($m) => "- {$m->field_label} ({$m->field_key})")
            ->implode("\n");

        Log::debug('[ProfileOptimizerAgent] Inyectando mutadores semánticos en gatekeeper.', [
            'archetype_id' => $archetype->id,
            'mutators'     => $mutators->count(),
        ]);

        return $base . "\n\nArchetype-specific fields present in this profile text (classify their values into positives, red_lines, or yellow_lines as appropriate):\n{$fieldLines}";
    }

    private function resolveOptimizerPrompt(?Archetype $archetype): string
    {
        if ($archetype !== null) {
            $legacyPrompt = $archetype->getPromptFor('optimizer');
            if ($legacyPrompt !== null) {
                Log::debug('[ProfileOptimizerAgent] Usando prompt legacy optimizer.', [
                    'archetype_id' => $archetype->id,
                ]);
                return $legacyPrompt;
            }
        }

        $template  = AiPromptTemplate::getBodyOrFail('player_profile_base');
        $injection = $archetype ? ($archetype->getPromptFor('player_profile') ?? '') : '';

        if ($injection !== '') {
            Log::debug('[ProfileOptimizerAgent] Inyectando prompt player_profile.', [
                'archetype_id' => $archetype?->id,
            ]);
        }

        return str_replace('{archetype_prompt_injection}', $injection, $template);
    }
}
