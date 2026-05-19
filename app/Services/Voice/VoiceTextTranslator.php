<?php

namespace App\Services\Voice;

use App\Application\Contracts\AiChatGateway;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

/**
 * Traduce textos a inglés para la síntesis de voz (TTS).
 * Las voces de Speechmatics solo están disponibles en inglés,
 * por lo que todo lo que se mande a TTS debe estar en inglés.
 *
 * Usa el modelo configurado para el agente 'talkator' (ligero y rápido).
 */
class VoiceTextTranslator
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $resolver,
    ) {}

    /**
     * Traduce texto al inglés usando el LLM.
     * Si no hay modelo configurado o la traducción falla, devuelve el texto original.
     */
    public function toEnglish(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return $text;
        }

        $model = $this->resolver->resolveExplicitAgentModel(null, 'talkator');

        if (! $model) {
            Log::warning('[VoiceTextTranslator] Sin modelo talkator configurado — texto enviado sin traducir al TTS');
            return $text;
        }

        $provider = $this->resolver->resolveAgentProvider(null, 'talkator');
        $options  = $provider ? ['_provider' => $provider] : [];

        Log::debug('[VoiceTextTranslator] Traduciendo a inglés', [
            'model'        => $model,
            'text_preview' => mb_substr($text, 0, 60),
        ]);

        $t0 = microtime(true);

        try {
            $response = $this->gateway->chat(
                $model,
                [
                    ['role' => 'system', 'content' => 'Translate the following text to natural, conversational English. Return ONLY the translation — no quotes, no explanations, no prefix.'],
                    ['role' => 'user',   'content' => $text],
                ],
                0.3,
                300,
                null,
                null,
                null,
                $options,
            );

            $translated = trim($response['text'] ?? '');

            if ($translated === '') {
                return $text;
            }

            Log::info('[VoiceTextTranslator] Traducido', [
                'ms'         => round((microtime(true) - $t0) * 1000),
                'original'   => mb_substr($text, 0, 60),
                'translated' => mb_substr($translated, 0, 60),
            ]);

            return $translated;
        } catch (\Throwable $e) {
            Log::warning('[VoiceTextTranslator] Traducción fallida — usando texto original', [
                'error' => $e->getMessage(),
            ]);
            return $text;
        }
    }
}
