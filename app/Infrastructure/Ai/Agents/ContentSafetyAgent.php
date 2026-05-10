<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Prompts\ContentSafetyPrompt;
use App\Infrastructure\Ai\Moderation\OpenAiModerationService;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class ContentSafetyAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
        private OpenAiModerationService $moderationService,
    ) {}

    /**
     * Returns true if the text is safe to store; false if it contains unsafe content.
     * Fails open: if the LLM call errors, logs a warning and returns true
     * (we don't want a model outage to block all registrations).
     */
    public function check(string $text, ?string $playerId = null): bool
    {
        $text = trim($text);

        if ($text === '') {
            return true;
        }

        // 1. Capa rápida y determinística: OpenAI Moderation API
        $moderation = $this->moderationService->check($text);
        if ($moderation['flagged']) {
            Log::warning('ContentSafetyAgent: contenido rechazado por OpenAI Moderation.', [
                'chars' => mb_strlen($text),
                'categories' => $moderation['categories'],
            ]);
            return false;
        }

        try {
            // 2. Capa semántica con LLM (Meta Llama via OpenRouter — fast, cheap)
            $model    = $this->settingsResolver->resolveAgentModel($playerId, 'safety');
            $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'safety');
            $options  = $provider ? ['_provider' => $provider] : [];

            $response = $this->gateway->chat($model, [
                ['role' => 'system', 'content' => ContentSafetyPrompt::getPrompt()],
                ['role' => 'user',   'content' => $text],
            ], 0.0, 10, null, null, null, $options);

            $verdict = strtoupper(trim($response['text'] ?? ''));

            // Respuesta vacía = modelo no pudo evaluar → fail open
            if ($verdict === '') {
                Log::warning('ContentSafetyAgent: veredicto vacío, fail open.', [
                    'model'      => $model,
                    'chars'      => mb_strlen($text),
                    'input_text' => mb_substr($text, 0, 500),
                    'raw'        => $response['text'] ?? null,
                ]);
                return true;
            }

            // Soporte para Llama Guard y similares:
            // SAFE -> true
            // UNSAFE -> false
            // unsafe\nS1... -> false
            $isSafe = str_starts_with($verdict, 'SAFE') && !str_contains($verdict, 'UNSAFE');

            if ($isSafe) {
                Log::debug('ContentSafetyAgent: contenido aprobado.', [
                    'model'   => $model,
                    'verdict' => $verdict,
                    'chars'   => mb_strlen($text),
                ]);
            } else {
                Log::warning('ContentSafetyAgent: contenido rechazado.', [
                    'model'      => $model,
                    'verdict'    => $verdict,
                    'chars'      => mb_strlen($text),
                    'input_text' => mb_substr($text, 0, 500),
                ]);
            }

            return $isSafe;
        } catch (\Throwable $e) {
            Log::warning('ContentSafetyAgent: check failed, defaulting to safe (fail open).', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Específicamente para mensajes de chat. Por ahora solo usa OpenAI Moderation por velocidad.
     */
    public function checkChat(string $text): bool
    {
        $moderation = $this->moderationService->check($text);
        $isSafe = !$moderation['flagged'];

        if (!$isSafe) {
            Log::warning('ContentSafetyAgent@checkChat: mensaje de chat RECHAZADO.', [
                'chars' => mb_strlen($text),
                'categories' => $moderation['categories'],
            ]);
        } else {
            Log::debug('ContentSafetyAgent@checkChat: mensaje de chat aprobado.', [
                'chars' => mb_strlen($text),
            ]);
        }

        return $isSafe;
    }
}
