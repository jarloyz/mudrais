<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Prompts\ProfileChunkerPrompt;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class ProfileChunkerAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Split raw_profile text into semantic chunks for per-entry Qdrant indexing.
     *
     * @return list<string>  Non-empty on success; single-item fallback on AI failure.
     */
    public function chunk(string $rawProfile, ?string $playerId = null): array
    {
        if (trim($rawProfile) === '') {
            return [];
        }

        $model = $this->settingsResolver->resolveAgentModel($playerId, 'gatekeeper');

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => ProfileChunkerPrompt::getSystemPrompt()],
            ['role' => 'user', 'content' => $rawProfile],
        ], 0.2, 600);

        $text = trim($response['text'] ?? '');
        $chunks = $this->parseChunks($text);

        if (empty($chunks)) {
            Log::warning('ProfileChunkerAgent: AI returned no valid chunks, using raw_profile as single entry.');

            return [trim($rawProfile)];
        }

        Log::debug('ProfileChunkerAgent: chunked raw_profile.', ['count' => count($chunks)]);

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function parseChunks(string $text): array
    {
        // Strip optional markdown code fences
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $clean = preg_replace('/```\s*$/i', '', $clean) ?? $clean;
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        if (! is_array($decoded)) {
            return [];
        }

        $chunks = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $chunks[] = trim($item);
            }
        }

        return $chunks;
    }
}
