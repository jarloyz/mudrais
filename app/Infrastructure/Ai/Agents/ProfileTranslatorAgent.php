<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Prompts\ProfileTranslatorPrompt;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class ProfileTranslatorAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Translate tag-related profile fields to English.
     * ONLY translates: red_lines, yellow_lines, affinities.
     * Does NOT touch raw_profile or narrative_style — those are stored as-is in DB.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function translate(array $data): array
    {
        $toTranslate = array_filter([
            'red_lines'    => $data['red_lines']    ?? null,
            'yellow_lines' => $data['yellow_lines'] ?? null,
            'preferences'  => $data['affinities']   ?? null,
        ], fn ($v) => $v !== null && $v !== []);

        if (empty($toTranslate)) {
            return $data;
        }

        $model    = $this->settingsResolver->resolveAgentModel(null, 'gatekeeper');
        $provider = $this->settingsResolver->resolveAgentProvider(null, 'gatekeeper');
        $options  = $provider ? ['_provider' => $provider] : [];

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => ProfileTranslatorPrompt::getPrompt()],
            ['role' => 'user',   'content' => json_encode($toTranslate, JSON_UNESCAPED_UNICODE)],
        ], 0.1, 1500, null, null, null, $options);

        $text       = trim($response['text'] ?? '');
        $text       = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text       = preg_replace('/```\s*$/i', '', $text) ?? $text;
        $translated = json_decode(trim($text), true);

        if (! is_array($translated)) {
            Log::warning('ProfileTranslatorAgent: failed to parse tag translation, using original.', [
                'raw' => $text,
            ]);

            return $data;
        }

        Log::debug('ProfileTranslatorAgent: tag translation complete.', [
            'model'  => $model,
            'fields' => array_keys($toTranslate),
        ]);

        return array_merge($data, array_filter([
            'red_lines'    => $translated['red_lines']    ?? null,
            'yellow_lines' => $translated['yellow_lines'] ?? null,
            'affinities'   => $translated['preferences']  ?? null,
        ], fn ($v) => $v !== null));
    }

}
