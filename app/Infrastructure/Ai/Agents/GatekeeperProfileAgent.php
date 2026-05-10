<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Parsers\ProfileTemplateParser;
use App\Infrastructure\Ai\Prompts\GatekeeperProfilePrompt;
use App\Domains\Matchmaking\Models\Archetype;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class GatekeeperProfileAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
        private ProfileTemplateParser $parser,
        private ProfileTranslatorAgent $translator,
    ) {}

    /**
     * @param ?Archetype $archetype  Si se provee, carga el system_prompt del arquetipo desde DB.
     *                               Si no, usa el prompt hardcodeado (compatibilidad legacy).
     */
    public function process(string $profileText, ?string $playerId = null, ?Archetype $archetype = null): ?array
    {
        Log::debug('[GatekeeperProfileAgent@process] Inicio.', [
            'player_id'    => $playerId,
            'archetype_id' => $archetype?->id,
        ]);

        // Step 1: regex parse — zero AI tokens for well-formed templates
        $parsed = $this->parser->parse($profileText);

        if ($this->parser->isComplete($parsed)) {
            Log::debug('GatekeeperProfileAgent: parsed via regex, translating to English.');

            // Translate text fields to English before returning
            return $this->translator->translate($parsed);
        }

        Log::debug('GatekeeperProfileAgent: template incomplete, falling back to AI.', [
            'missing' => $this->missingFields($parsed),
        ]);

        // Step 2: AI fallback — carga prompt desde DB si hay arquetipo, sino usa hardcoded
        $systemPrompt = $this->resolveSystemPrompt($archetype);
        $model        = $this->settingsResolver->resolveAgentModel($playerId, 'gatekeeper');
        $provider     = $this->settingsResolver->resolveAgentProvider($playerId, 'gatekeeper');
        $options      = $provider ? ['_provider' => $provider] : [];

        Log::debug('[GatekeeperProfileAgent@process] Llamando LLM.', [
            'model'         => $model,
            'prompt_source' => $archetype ? 'db' : 'hardcoded',
        ]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => "Partial JSON:\n" . json_encode($parsed, JSON_UNESCAPED_UNICODE) . "\n\nOriginal text:\n" . $profileText],
        ], 0.1, 1500, null, null, null, $options);

        $text = $this->extractJson($response['text'] ?? '');
        $json = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GatekeeperProfileAgent: AI fallback also failed, returning partial regex result.', [
                'raw'    => $text,
                'parsed' => $parsed,
            ]);

            $partial = array_filter($parsed, fn ($v) => $v !== null && $v !== '' && $v !== []);

            return $partial !== [] ? $partial : null;
        }

        // AI output takes priority (instructed to return English).
        // Regex-extracted values fill only what AI left as null.
        return array_merge(
            array_filter($parsed, fn ($v) => $v !== null && $v !== '' && $v !== []),
            $json,
        );
    }

    private function resolveSystemPrompt(?Archetype $archetype): string
    {
        if ($archetype !== null) {
            $dbPrompt = $archetype->getPromptFor('gatekeeper');
            if ($dbPrompt !== null) {
                return $dbPrompt;
            }
        }

        return GatekeeperProfilePrompt::getFallbackPrompt();
    }

    private function extractJson(string $text): string
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/(\{.*\})/s', $text, $matches)) {
            return trim($matches[1]);
        }

        return trim($text);
    }

    /**
     * @param array<string, mixed> $parsed
     * @return list<string>
     */
    private function missingFields(array $parsed): array
    {
        $missing = [];

        foreach ($parsed as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
