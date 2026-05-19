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
     * Fails open: errors default to safe to avoid blocking on model/API outages.
     */
    public function check(string $text, ?string $playerId = null): bool
    {
        $text = trim($text);

        if ($text === '') {
            return true;
        }

        $driver = $this->settingsResolver->resolveSafetyDriver($playerId);

        Log::debug('[ContentSafetyAgent@check] Inicio', [
            'driver'    => $driver,
            'chars'     => mb_strlen($text),
            'player_id' => $playerId,
        ]);

        if ($driver === 'openai_moderation') {
            return $this->checkViaOpenAiModeration($text, '[ContentSafetyAgent@check]');
        }

        return $this->checkViaLlm($text, $playerId, ContentSafetyPrompt::getPrompt(), '[ContentSafetyAgent@check]');
    }

    /**
     * Safety check for interview context: detects both unsafe content AND manipulation attempts
     * (prompt injection, jailbreak, instruction override) in a single LLM call.
     *
     * Returns ['is_safe' => bool, 'is_manipulation' => bool].
     * Fails open: errors default to ['is_safe' => true, 'is_manipulation' => false].
     *
     * Degrades gracefully when the configured model outputs Llama Guard-style SAFE/UNSAFE
     * instead of JSON — manipulation detection is skipped in that case.
     */
    public function checkForInterview(string $text, ?string $playerId = null): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['is_safe' => true, 'is_manipulation' => false];
        }

        $driver = $this->settingsResolver->resolveSafetyDriver($playerId);

        Log::debug('[ContentSafetyAgent@checkForInterview] Inicio', [
            'driver'    => $driver,
            'chars'     => mb_strlen($text),
            'player_id' => $playerId,
        ]);

        if ($driver === 'openai_moderation') {
            $isSafe = $this->checkViaOpenAiModeration($text, '[ContentSafetyAgent@checkForInterview]');
            return ['is_safe' => $isSafe, 'is_manipulation' => false];
        }

        return $this->checkForInterviewViaLlm($text, $playerId);
    }

    /**
     * Específicamente para mensajes de chat. Respeta el safety_driver configurado.
     */
    public function checkChat(string $text, ?string $playerId = null): bool
    {
        $text = trim($text);

        if ($text === '') {
            return true;
        }

        $driver = $this->settingsResolver->resolveSafetyDriver($playerId);

        if ($driver === 'openai_moderation') {
            return $this->checkViaOpenAiModeration($text, '[ContentSafetyAgent@checkChat]');
        }

        return $this->checkViaLlm($text, $playerId, ContentSafetyPrompt::getPrompt(), '[ContentSafetyAgent@checkChat]');
    }

    // ── Implementaciones por driver ──────────────────────────────────────────

    private function checkViaOpenAiModeration(string $text, string $ctx): bool
    {
        $moderation = $this->moderationService->check($text);

        if ($moderation['flagged']) {
            Log::warning("{$ctx} Rechazado por OpenAI Moderation.", [
                'chars'      => mb_strlen($text),
                'categories' => $moderation['categories'],
            ]);
            return false;
        }

        Log::debug("{$ctx} Aprobado por OpenAI Moderation.", ['chars' => mb_strlen($text)]);
        return true;
    }

    private function checkViaLlm(string $text, ?string $playerId, string $systemPrompt, string $ctx): bool
    {
        try {
            $model    = $this->settingsResolver->resolveAgentModel($playerId, 'safety');
            $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'safety');
            $options  = $provider ? ['_provider' => $provider] : [];

            $response = $this->gateway->chat($model, [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $text],
            ], 0.0, 10, null, null, null, $options);

            $verdict = strtoupper(trim($response['text'] ?? ''));

            if ($verdict === '') {
                Log::warning("{$ctx} Veredicto vacío — fail open.", [
                    'model' => $model,
                    'chars' => mb_strlen($text),
                ]);
                return true;
            }

            // Soporte para Llama Guard y similares: SAFE → true, UNSAFE / unsafe\nS1... → false
            $isSafe = str_starts_with($verdict, 'SAFE') && ! str_contains($verdict, 'UNSAFE');

            Log::debug("{$ctx} Veredicto LLM.", [
                'model'   => $model,
                'verdict' => mb_substr($verdict, 0, 60),
                'is_safe' => $isSafe,
                'chars'   => mb_strlen($text),
            ]);

            return $isSafe;
        } catch (\Throwable $e) {
            Log::warning("{$ctx} Excepción LLM — fail open.", ['error' => $e->getMessage()]);
            return true;
        }
    }

    private function checkForInterviewViaLlm(string $text, ?string $playerId): array
    {
        try {
            $model    = $this->settingsResolver->resolveAgentModel($playerId, 'safety');
            $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'safety');
            $options  = $provider ? ['_provider' => $provider] : [];

            $response = $this->gateway->chat($model, [
                ['role' => 'system', 'content' => ContentSafetyPrompt::getInterviewPrompt()],
                ['role' => 'user',   'content' => $text],
            ], 0.0, 30, null, null, null, $options);

            $raw     = trim($response['text'] ?? '');
            $decoded = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['safe'])) {
                $isSafe         = (bool) $decoded['safe'];
                $isManipulation = (bool) ($decoded['manipulation'] ?? false);
                Log::debug('[ContentSafetyAgent@checkForInterview] Resultado JSON', [
                    'is_safe'         => $isSafe,
                    'is_manipulation' => $isManipulation,
                    'model'           => $model,
                ]);
                return ['is_safe' => $isSafe, 'is_manipulation' => $isManipulation];
            }

            // Degradación: modelo retorna Llama Guard style (SAFE/UNSAFE) sin soporte JSON
            $verdict = strtoupper($raw);
            if ($verdict !== '') {
                $isSafe = str_starts_with($verdict, 'SAFE') && ! str_contains($verdict, 'UNSAFE');
                Log::debug('[ContentSafetyAgent@checkForInterview] Respuesta Llama Guard (sin detección de manipulación)', [
                    'is_safe' => $isSafe,
                    'model'   => $model,
                ]);
                return ['is_safe' => $isSafe, 'is_manipulation' => false];
            }

            Log::warning('[ContentSafetyAgent@checkForInterview] Respuesta vacía — fail open.', [
                'model' => $model,
                'chars' => mb_strlen($text),
            ]);
            return ['is_safe' => true, 'is_manipulation' => false];

        } catch (\Throwable $e) {
            Log::warning('[ContentSafetyAgent@checkForInterview] Excepción — fail open.', [
                'error' => $e->getMessage(),
            ]);
            return ['is_safe' => true, 'is_manipulation' => false];
        }
    }
}
