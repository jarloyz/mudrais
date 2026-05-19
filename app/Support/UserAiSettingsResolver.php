<?php

namespace App\Support;

use App\Models\AgentConfig;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class UserAiSettingsResolver
{
    /**
     * Resolve the full AI settings applying the 4-level hierarchy:
     *   env defaults → global → player → vault → scene
     *
     * Each level deep-merges on top of the previous. Any level can be
     * omitted by passing null — the remaining levels still apply.
     *
     * @return array{
     *   provider:string,
     *   timeout_ms:int,
     *   models:array{writer:string,qa:string},
     *   agents:array<string, string>,
     *   qa_policy:array{simple:string,complex:string},
     *   parameters:array{
     *     writer:array{
     *       temperature:float,
     *       max_output_tokens:int,
     *       top_p:float,
     *       presence_penalty:float,
     *       frequency_penalty:float,
     *       style_profile:string,
     *       style_notes:string,
     *       response_length:string
     *     }
     *   }
     * }
     */
    public function resolve(
        ?string $playerId = null,
        ?string $vaultId = null,
        ?string $sceneId = null,
    ): array {
        // DB is the single source of truth for models and parameters.
        // We initialize with config() defaults to ensure we always have a model (fail-safe).
        $resolved = [
            'provider'   => config('historia.ai.provider', 'openrouter'),
            'timeout_ms' => config('historia.ai.timeout_ms', 120000),
            'models'     => [
                'gatekeeper'     => config('historia.ai.models.gatekeeper', ''),
                'safety'         => config('historia.ai.models.safety', ''),
                'embedding'      => config('historia.ai.models.embedding', ''),
                'librarian'      => config('historia.ai.models.librarian', ''),
                'writer'         => config('historia.ai.models.writer', ''),
                'critic'         => config('historia.ai.models.critic', ''),
                'optimizer'      => config('historia.ai.models.optimizer', ''),
                'optimizer_fast' => config('historia.ai.models.optimizer_fast', ''),
            ],
            'agents'              => [],
            'agent_providers'     => [],
            'agent_reasoning'     => [],   // agentKey => bool
            'agent_budget_tokens' => [],   // agentKey => int
            'safety_driver'       => 'llm', // 'llm' | 'openai_moderation'
            'qa_policy' => [
                'simple'  => 'adaptive',
                'complex' => 'adaptive',
            ],
            'parameters' => [
                'writer' => [
                    'temperature'       => (float) config('historia.ai.writer.temperature', 0.7),
                    'max_output_tokens' => (int) config('historia.ai.writer.max_output_tokens', 4000),
                    'top_p'             => (float) config('historia.ai.writer.top_p', 1.0),
                    'presence_penalty'  => (float) config('historia.ai.writer.presence_penalty', 0.15),
                    'frequency_penalty' => (float) config('historia.ai.writer.frequency_penalty', 0.1),
                    'style_profile'     => (string) config('historia.ai.writer.style_profile', 'cinematico'),
                    'style_notes'       => (string) config('historia.ai.writer.style_notes', ''),
                    'response_length'   => (string) config('historia.ai.writer.response_length', 'medio'),
                ],
            ],
        ];

        // Seed agents map with config defaults too
        foreach ($resolved['models'] as $key => $model) {
            if ($model !== '') {
                $resolved['agents'][$key] = $model;
            }
        }

        // Un único query con OR conditions sobre índices (global → player → vault → scene).
        if (Schema::hasTable('agent_configs')) {
            $layers = AgentConfig::resolveHierarchy($playerId, $vaultId, $sceneId);
            foreach ($layers as $layer) {
                $resolved = $this->applyConfigLayer($resolved, $layer);
            }
        }

        return $resolved;
    }

    public function resolveAgentModel(
        ?string $playerId,
        string $agentKey,
        ?string $vaultId = null,
        ?string $sceneId = null,
    ): string {
        $resolved = $this->resolve($playerId, $vaultId, $sceneId);

        $aliasMap = [
            'qa'                   => 'critic',
            'writer_qa_pass'       => 'writer',
            'summarizer'           => 'writer',
            'director'             => 'writer',
            'editor'               => 'writer',
            'statekeeper'          => 'critic',
            'quest'                => 'librarian',
            'quest_scaffolder'     => 'writer',
            'content_safety'       => 'safety',
            'interviewer'          => 'gatekeeper',
            'interview_gatekeeper' => 'gatekeeper',
            'interview_optimizer'  => 'optimizer',
            'talkator'             => 'gatekeeper',
            'voice_talkator'       => 'gatekeeper',
        ];

        $mappedKey = $aliasMap[$agentKey] ?? $agentKey;

        $candidate = $resolved['agents'][$mappedKey] ?? null;

        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }

        $modelFallback = $resolved['models'][$mappedKey] ?? null;
        if (is_string($modelFallback) && trim($modelFallback) !== '') {
            return trim($modelFallback);
        }

        // Si el agente tiene un provider asignado, intentar usar su default_model
        $providerSlug = $resolved['agent_providers'][$mappedKey] ?? null;
        if ($providerSlug) {
            $defaultModel = AiProvider::where('slug', $providerSlug)->value('default_model');
            if (is_string($defaultModel) && trim($defaultModel) !== '') {
                return trim($defaultModel);
            }
        }

        // safety falls back to gatekeeper when not explicitly configured
        if ($mappedKey === 'safety') {
            $gatekeeperFallback = $resolved['agents']['gatekeeper'] ?? $resolved['models']['gatekeeper'] ?? null;
            if (is_string($gatekeeperFallback) && trim($gatekeeperFallback) !== '') {
                return trim($gatekeeperFallback);
            }
            return 'meta-llama/llama-guard-3-8b';
        }

        // optimizer falls back to writer when not explicitly configured
        if ($mappedKey === 'optimizer') {
            $writerFallback = $resolved['agents']['writer'] ?? $resolved['models']['writer'] ?? null;
            if (is_string($writerFallback) && trim($writerFallback) !== '') {
                return trim($writerFallback);
            }
            return 'meta-llama/llama-3-8b-instruct:free';
        }

        // optimizer_fast falls back to gatekeeper (modelo rápido/ligero, sin thinking)
        if ($mappedKey === 'optimizer_fast') {
            $gatekeeperFallback = $resolved['agents']['gatekeeper'] ?? $resolved['models']['gatekeeper'] ?? null;
            if (is_string($gatekeeperFallback) && trim($gatekeeperFallback) !== '') {
                return trim($gatekeeperFallback);
            }
            return 'meta-llama/llama-3.1-8b-instruct';
        }

        return $resolved['models']['writer'] ?: 'meta-llama/llama-3-8b-instruct:free';
    }

    /**
     * Devuelve el modelo solo si está configurado explícitamente para el agente.
     * Devuelve null si no hay modelo configurado (sin fallbacks).
     * Usar en jobs que deben abortar claramente cuando falta configuración.
     */
    public function resolveExplicitAgentModel(
        ?string $playerId,
        string $agentKey,
        ?string $vaultId = null,
        ?string $sceneId = null,
    ): ?string {
        $resolved  = $this->resolve($playerId, $vaultId, $sceneId);
        $candidate = $resolved['agents'][$agentKey] ?? null;

        return (is_string($candidate) && trim($candidate) !== '')
            ? trim($candidate)
            : null;
    }

    public function resolveAgentProvider(
        ?string $playerId,
        string $agentKey,
        ?string $vaultId = null,
        ?string $sceneId = null,
    ): ?string {
        $resolved = $this->resolve($playerId, $vaultId, $sceneId);

        $aliasMap = [
            'qa'                => 'critic',
            'writer_qa_pass'    => 'writer',
            'summarizer'        => 'writer',
            'director'          => 'writer',
            'editor'            => 'writer',
            'statekeeper'       => 'critic',
            'quest'             => 'librarian',
            'quest_scaffolder'  => 'writer',
            'content_safety'       => 'safety',
            'interviewer'          => 'gatekeeper',
            'interview_gatekeeper' => 'gatekeeper',
            'interview_optimizer'  => 'optimizer',
            'talkator'             => 'gatekeeper',
            'voice_talkator'       => 'gatekeeper',
        ];

        $mappedKey = $aliasMap[$agentKey] ?? $agentKey;

        $provider = $resolved['agent_providers'][$mappedKey] ?? null;

        return (is_string($provider) && trim($provider) !== '') ? trim($provider) : null;
    }

    public function resolveQaExecutionMode(
        ?string $playerId,
        string $sceneMode,
        ?bool $isFreeModel = null,
        ?string $vaultId = null,
        ?string $sceneId = null,
    ): string {
        $resolved = $this->resolve($playerId, $vaultId, $sceneId);
        $policy = $resolved['qa_policy'][$sceneMode] ?? 'adaptive';

        return match ($policy) {
            'auto' => 'auto',
            'manual' => 'manual',
            'disabled' => 'disabled',
            default => $isFreeModel === true ? 'auto' : 'manual',
        };
    }

    public function resolveAgentReasoning(
        ?string $playerId,
        string $agentKey,
        ?string $vaultId = null,
        ?string $sceneId = null,
    ): bool {
        $resolved = $this->resolve($playerId, $vaultId, $sceneId);

        $mappedKey = $aliasMap[$agentKey] ?? $agentKey;
        return (bool) ($resolved['agent_reasoning'][$mappedKey] ?? false);
    }

    public function resolveSafetyDriver(?string $playerId = null, ?string $vaultId = null, ?string $sceneId = null): string
    {
        $resolved = $this->resolve($playerId, $vaultId, $sceneId);
        return $resolved['safety_driver'];
    }

    public function resolveAgentBudgetTokens(
        ?string $playerId,
        string $agentKey,
        ?string $vaultId = null,
        ?string $sceneId = null,
        int $default = 8000,
    ): int {
        $resolved = $this->resolve($playerId, $vaultId, $sceneId);
        return (int) ($resolved['agent_budget_tokens'][$agentKey] ?? $default);
    }

    /**
     * Apply one config layer's scalar fields and deep-merge its settings_json
     * on top of the currently resolved array.
     *
     * @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    private function applyConfigLayer(array $resolved, Model $config): array
    {
        if (filled($config->provider)) {
            $resolved['provider'] = (string) $config->provider;
        }

        if (filled($config->writer_model)) {
            $resolved['models']['writer'] = (string) $config->writer_model;
            $resolved['agents']['writer'] = (string) $config->writer_model;
        }

        if (filled($config->qa_model)) {
            // qa_model column maps to critic in the new agent architecture
            $resolved['models']['critic'] = (string) $config->qa_model;
            $resolved['agents']['critic'] = (string) $config->qa_model;
        }

        if ($config->timeout_ms !== null) {
            $resolved['timeout_ms'] = (int) $config->timeout_ms;
        }

        $settings = is_array($config->settings_json) ? $config->settings_json : [];

        $agentSettings = $settings['agents'] ?? null;
        if (is_array($agentSettings)) {
            foreach ($agentSettings as $agentKey => $agentConfig) {
                if (! is_array($agentConfig)) {
                    continue;
                }

                $model = $agentConfig['model'] ?? null;
                if (is_string($model) && trim($model) !== '') {
                    $resolved['agents'][(string) $agentKey] = trim($model);
                }

                $providerOverride = $agentConfig['provider'] ?? null;
                if (is_string($providerOverride) && trim($providerOverride) !== '') {
                    $resolved['agent_providers'][(string) $agentKey] = trim($providerOverride);

                    // When a provider is set with no explicit model, evict config-seeded defaults
                    // so resolveAgentModel() falls through to AiProvider.default_model.
                    if (! is_string($agentConfig['model'] ?? null) || trim((string) ($agentConfig['model'] ?? '')) === '') {
                        unset($resolved['agents'][(string) $agentKey], $resolved['models'][(string) $agentKey]);
                    }
                }

                if (isset($agentConfig['reasoning']) && is_bool($agentConfig['reasoning'])) {
                    $resolved['agent_reasoning'][(string) $agentKey] = $agentConfig['reasoning'];
                }

                if (isset($agentConfig['budget_tokens']) && is_numeric($agentConfig['budget_tokens'])) {
                    $resolved['agent_budget_tokens'][(string) $agentKey] = (int) $agentConfig['budget_tokens'];
                }
            }
        }

        foreach (['gatekeeper', 'safety', 'embedding', 'librarian', 'writer', 'critic', 'optimizer', 'optimizer_fast'] as $primary) {
            if (isset($resolved['agents'][$primary])) {
                $resolved['models'][$primary] = (string) $resolved['agents'][$primary];
            }
        }

        $parameterSettings = $settings['parameters']['writer'] ?? null;
        if (is_array($parameterSettings)) {
            foreach (['temperature', 'top_p', 'presence_penalty', 'frequency_penalty'] as $key) {
                if (is_numeric($parameterSettings[$key] ?? null)) {
                    $resolved['parameters']['writer'][$key] = (float) $parameterSettings[$key];
                }
            }

            if (is_numeric($parameterSettings['max_output_tokens'] ?? null)) {
                $resolved['parameters']['writer']['max_output_tokens'] = (int) $parameterSettings['max_output_tokens'];
            }

            if (is_string($parameterSettings['style_profile'] ?? null) && trim((string) $parameterSettings['style_profile']) !== '') {
                $resolved['parameters']['writer']['style_profile'] = trim((string) $parameterSettings['style_profile']);
            }

            if (is_string($parameterSettings['style_notes'] ?? null)) {
                $resolved['parameters']['writer']['style_notes'] = trim((string) $parameterSettings['style_notes']);
            }

            if (is_string($parameterSettings['response_length'] ?? null) && trim((string) $parameterSettings['response_length']) !== '') {
                $resolved['parameters']['writer']['response_length'] = trim((string) $parameterSettings['response_length']);
            }
        }

        $qaPolicySettings = $settings['qa_policy'] ?? null;
        if (is_array($qaPolicySettings)) {
            foreach (['simple', 'complex'] as $modeKey) {
                $value = $qaPolicySettings[$modeKey] ?? null;
                if (is_string($value) && in_array(trim($value), ['adaptive', 'auto', 'manual', 'disabled'], true)) {
                    $resolved['qa_policy'][$modeKey] = trim($value);
                }
            }
        }

        $safetyDriver = $settings['safety_driver'] ?? null;
        if (is_string($safetyDriver) && in_array($safetyDriver, ['llm', 'openai_moderation'], true)) {
            $resolved['safety_driver'] = $safetyDriver;
        }

        return $resolved;
    }
}
