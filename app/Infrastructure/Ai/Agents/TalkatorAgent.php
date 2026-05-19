<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Prompts\TalkatorPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class TalkatorAgent
{
    private const TEMPERATURE = 0.85;
    private const MAX_TOKENS  = 120;

    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Genera una frase de transición hablada para el agente de voz.
     *
     * Si se proporciona $onChunk, el texto se emite en streaming chunk a chunk
     * (misma firma que AiChatGateway::chat onChunk). Devuelve siempre el texto
     * completo acumulado para logging.
     *
     * La respuesta siempre es texto plano hablable: sin markdown, sin preguntas,
     * máximo ~15 palabras.
     */
    public function respond(
        string $transcript,
        string $locale = 'es',
        ?callable $onChunk = null,
        ?string $archetypeId = null,
        ?string $playerId = null,
    ): string {
        Log::debug('[TalkatorAgent@respond] Inicio', [
            'locale'       => $locale,
            'archetype_id' => $archetypeId,
            'transcript'   => mb_substr($transcript, 0, 80),
            'streaming'    => $onChunk !== null,
        ]);

        $systemPrompt = $this->resolveSystemPrompt($archetypeId, $locale);
        $model        = $this->settingsResolver->resolveAgentModel($playerId, 'talkator');
        $provider     = $this->settingsResolver->resolveAgentProvider($playerId, 'talkator');
        $options      = $provider ? ['_provider' => $provider] : [];

        Log::info('[TalkatorAgent@respond] Llamando AI', ['model' => $model]);

        try {
            $accumulated = '';

            $chunkCallback = $onChunk
                ? function (string $chunk) use ($onChunk, &$accumulated): void {
                    $accumulated .= $chunk;
                    $onChunk($chunk);
                }
                : function (string $chunk) use (&$accumulated): void {
                    $accumulated .= $chunk;
                };

            $this->gateway->chat(
                $model,
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $transcript],
                ],
                self::TEMPERATURE,
                self::MAX_TOKENS,
                null,
                null,
                $chunkCallback,
                $options,
            );

            $result = $this->sanitize($accumulated);

            if ($result === '') {
                return $this->fallback($transcript, $locale, $onChunk);
            }

            // Si el resultado es demasiado largo, usar fallback en lugar de cortarlo.
            if (mb_strlen($result) > 350) {
                Log::warning('[TalkatorAgent@respond] Respuesta demasiado larga, usando fallback', [
                    'length' => mb_strlen($result),
                ]);
                return $this->fallback($transcript, $locale, $onChunk);
            }

            Log::info('[TalkatorAgent@respond] Respuesta generada', [
                'length' => mb_strlen($result),
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('[TalkatorAgent@respond] Error al llamar AI', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $this->fallback($transcript, $locale, $onChunk);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolveSystemPrompt(?string $archetypeId, string $locale): string
    {
        $phpFallback = TalkatorPrompt::getFallback($locale);

        // 1. Prompt específico del arquetipo
        if ($archetypeId) {
            $archetype = Archetype::find($archetypeId);
            $prompt    = $archetype?->getPromptFor('voice_talkator');

            if ($prompt !== null) {
                Log::debug('[TalkatorAgent] Usando prompt de arquetipo', ['archetype_id' => $archetypeId]);
                return $prompt;
            }
        }

        // 2. Template global editable en DB
        $globalTemplate = AiPromptTemplate::getBody('voice_talkator', '');

        if ($globalTemplate !== '') {
            return $globalTemplate;
        }

        return $phpFallback;
    }

    /**
     * Limpia markdown accidental y comillas envolventes del output del LLM.
     */
    private function sanitize(string $raw): string
    {
        // Strip markdown asteriscos, guiones bajos, almohadillas, comillas invertidas
        $clean = preg_replace('/[*_`#]/', '', $raw) ?? $raw;

        // Strip comillas envolventes simples o dobles que algunos modelos añaden
        $clean = preg_replace('/^["\'](.*)["\']$/su', '$1', trim($clean)) ?? trim($clean);

        return trim($clean);
    }

    /**
     * Envía una frase de fallback estática (rotativa por transcript hash).
     * Si hay un callback de streaming, lo llama con la frase completa de una vez.
     */
    private function fallback(string $transcript, string $locale, ?callable $onChunk): string
    {
        $phrase = __('discord.voice_talkator_fallback_' . (abs(crc32($transcript)) % 5));

        if ($onChunk !== null) {
            $onChunk($phrase);
        }

        return $phrase;
    }
}
