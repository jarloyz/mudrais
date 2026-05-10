<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Prompts\StyleGatekeeperPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class StyleOptimizerAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Two-step pipeline:
     *   1. Gatekeeper: translates raw text → clean JSON array of facts
     *   2. Optimizer: converts fact array → dense English paragraph for embedding
     *
     * Throws \RuntimeException when a model is unconfigured or any step fails,
     * so callers can abort cleanly instead of storing incorrect data.
     */
    public function optimize(string $rawText, ?string $playerId = null, ?string $archetypeId = null): string
    {
        $rawText = trim($rawText);

        if ($rawText === '') {
            return $rawText;
        }

        Log::debug('[StyleOptimizerAgent] Iniciando optimización', [
            'player_id'    => $playerId,
            'archetype_id' => $archetypeId,
            'input_chars'  => mb_strlen($rawText),
        ]);

        // ── Step 1: Gatekeeper — extract clean facts ──────────────────────────
        $gatekeeperModel    = $this->settingsResolver->resolveAgentModel($playerId, 'gatekeeper');
        $gatekeeperProvider = $this->settingsResolver->resolveAgentProvider($playerId, 'gatekeeper');
        $gatekeeperOptions  = $gatekeeperProvider ? ['_provider' => $gatekeeperProvider] : [];

        if ($gatekeeperModel === '') {
            Log::error('[StyleOptimizerAgent] Modelo gatekeeper no configurado — imposible optimizar', [
                'player_id' => $playerId,
            ]);
            throw new \RuntimeException('Modelo gatekeeper no configurado.');
        }

        $gatekeeperResponse = $this->gateway->chat($gatekeeperModel, [
            ['role' => 'system', 'content' => $this->resolveGatekeeperPrompt($archetypeId)],
            ['role' => 'user',   'content' => $rawText],
        ], 0.0, 1200, null, null, null, $gatekeeperOptions);

        $rawJson = trim($gatekeeperResponse['text'] ?? '');
        $rawJson = preg_replace('/^```(?:json)?\s*/i', '', $rawJson) ?? $rawJson;
        $rawJson = preg_replace('/```\s*$/i', '', $rawJson) ?? $rawJson;
        $rawJson = trim($rawJson);

        $structured = json_decode($rawJson, true);

        // Gatekeeper now returns {positives:[], red_lines:[], yellow_lines:[]}
        // Extract only the positive affinities to pass to the Optimizer.
        $positives = is_array($structured['positives'] ?? null) ? $structured['positives'] : [];

        if (empty($positives)) {
            Log::warning('[StyleOptimizerAgent] Gatekeeper no extrajo facts positivos — texto de entrada insuficiente', [
                'player_id'  => $playerId,
                'raw_json'   => $rawJson,
            ]);
            throw new \RuntimeException('Gatekeeper no pudo extraer facts del perfil.');
        }

        Log::debug('[StyleOptimizerAgent] Facts extraídos por gatekeeper', [
            'player_id'  => $playerId,
            'positives'  => count($positives),
            'red_lines'  => count($structured['red_lines']   ?? []),
            'yellow_lines' => count($structured['yellow_lines'] ?? []),
        ]);

        // ── Step 2: Optimizer — build dense semantic paragraph (positives only) ─
        $optimizerModel    = $this->settingsResolver->resolveAgentModel($playerId, 'optimizer');
        $optimizerProvider = $this->settingsResolver->resolveAgentProvider($playerId, 'optimizer');
        $optimizerOptions  = $optimizerProvider ? ['_provider' => $optimizerProvider] : [];

        if ($optimizerModel === '') {
            Log::error('[StyleOptimizerAgent] Modelo optimizer no configurado — imposible optimizar', [
                'player_id' => $playerId,
            ]);
            throw new \RuntimeException('Modelo optimizer no configurado.');
        }

        $optimizerResponse = $this->gateway->chat($optimizerModel, [
            ['role' => 'system', 'content' => $this->resolveOptimizerPrompt($archetypeId)],
            ['role' => 'user',   'content' => json_encode($positives, JSON_UNESCAPED_UNICODE)],
        ], 0.1, 1200, null, null, null, $optimizerOptions);

        $optimized = trim($optimizerResponse['text'] ?? '');

        if ($optimized === '') {
            Log::error('[StyleOptimizerAgent] Optimizer devolvió respuesta vacía', [
                'player_id' => $playerId,
            ]);
            throw new \RuntimeException('Optimizer devolvió respuesta vacía.');
        }

        Log::debug('[StyleOptimizerAgent] Texto optimizado para embedding', [
            'player_id'       => $playerId,
            'original_chars'  => mb_strlen($rawText),
            'optimized_chars' => mb_strlen($optimized),
        ]);

        return $optimized;
    }

    private function resolveGatekeeperPrompt(?string $archetypeId): string
    {
        if ($archetypeId !== null) {
            $archetype = Archetype::with('prompts')->find($archetypeId);
            $prompt    = $archetype?->getPromptFor('gatekeeper');
            if ($prompt !== null) {
                Log::debug('[StyleOptimizerAgent] Usando gatekeeper del arquetipo.', [
                    'archetype_id' => $archetypeId,
                ]);
                return $prompt;
            }
        }

        Log::debug('[StyleOptimizerAgent] Fallback: style_gatekeeper genérico.');
        return StyleGatekeeperPrompt::getPrompt();
    }

    private function resolveOptimizerPrompt(?string $archetypeId): string
    {
        $archetype = $archetypeId ? Archetype::find($archetypeId) : null;

        $injection = $archetype ? ($archetype->getPromptFor('player_profile') ?? '') : '';

        if ($archetype !== null) {
            $legacyPrompt = $archetype->getPromptFor('optimizer');
            if ($legacyPrompt !== null) {
                $resolved = str_replace('{archetype_prompt_injection}', $injection, $legacyPrompt);
                Log::debug('[StyleOptimizerAgent] Usando prompt legacy optimizer.', [
                    'archetype_id'    => $archetype->id,
                    'injection_chars' => mb_strlen($injection),
                ]);
                return $resolved;
            }
        }

        $template = AiPromptTemplate::getBodyOrFail('player_profile_base');

        if ($injection !== '') {
            Log::debug('[StyleOptimizerAgent] Inyectando prompt player_profile.', ['archetype_id' => $archetype?->id]);
        }

        return str_replace('{archetype_prompt_injection}', $injection, $template);
    }
}
