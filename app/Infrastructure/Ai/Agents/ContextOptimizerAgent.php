<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class ContextOptimizerAgent
{
    // ~2250 tokens — safe para modelos con 4096 tokens de contexto total
    private const MAX_PROMPT_CHARS = 9000;

    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * @return array{optimized_text_en: string, semantic_tag_query: string}
     * @throws \RuntimeException
     */
    public function optimize(string $builtPrompt, ?string $playerId = null): array
    {
        Log::debug('[ContextOptimizerAgent@optimize] Enviando prompt al LLM', [
            'prompt_length' => strlen($builtPrompt),
            'player_id'     => $playerId,
        ]);

        $optimizerModel = $this->settingsResolver->resolveAgentModel($playerId, 'optimizer');

        if ($optimizerModel === '') {
            Log::error('[ContextOptimizerAgent@optimize] Modelo optimizer no configurado', [
                'player_id' => $playerId,
            ]);
            throw new \RuntimeException('Modelo optimizer no configurado.');
        }

        $systemEnforcement = implode("\n", [
            'CRITICAL OUTPUT FORMAT: Respond ONLY with a valid JSON object.',
            'No markdown fences (```json), no prose, no preambles outside your thinking block.',
            'Required keys: "optimized_text_en" (string) and "semantic_tag_query" (string).',
            'Any output outside a valid JSON object is a fatal error.',
        ]);

        if (mb_strlen($builtPrompt) > self::MAX_PROMPT_CHARS) {
            Log::warning('[ContextOptimizerAgent@optimize] Prompt truncado por exceso de longitud', [
                'original_chars' => mb_strlen($builtPrompt),
                'truncated_to'   => self::MAX_PROMPT_CHARS,
                'player_id'      => $playerId,
            ]);
            $builtPrompt = mb_substr($builtPrompt, 0, self::MAX_PROMPT_CHARS);
        }

        $response = $this->gateway->chat($optimizerModel, [
            ['role' => 'system', 'content' => $systemEnforcement],
            ['role' => 'user',   'content' => $builtPrompt],
        ], 0.1, 1500);

        $rawText = $response['text'] ?? '';
        $rawJson = trim($rawText);

        // Remove markdown fences
        $rawJson = preg_replace('/^```(?:json)?\s*/i', '', $rawJson) ?? $rawJson;
        $rawJson = preg_replace('/```\s*$/i', '', $rawJson) ?? $rawJson;
        $rawJson = trim($rawJson);

        $parsed = json_decode($rawJson, true);

        // Fallback: try to find anything that looks like a JSON object {...}
        if ($parsed === null) {
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $rawText, $matches)) {
                $rawJson = $matches[0];
                $parsed = json_decode($rawJson, true);
            }
        }

        if ($parsed === null || ! isset($parsed['optimized_text_en'], $parsed['semantic_tag_query'])) {
            Log::error('[ContextOptimizerAgent@optimize] JSON inválido del LLM', [
                'raw_response_head' => substr($rawText, 0, 500),
                'attempted_json'    => substr($rawJson, 0, 500),
                'json_error'        => json_last_error_msg(),
            ]);
            throw new \RuntimeException('LLM devolvió JSON inválido: faltan campos requeridos o formato incorrecto');
        }

        Log::info('[ContextOptimizerAgent@optimize] Optimización exitosa', [
            'optimized_text_length'  => strlen((string)$parsed['optimized_text_en']),
            'semantic_tag_query_len' => strlen((string)$parsed['semantic_tag_query']),
        ]);

        return $parsed;
    }
}
