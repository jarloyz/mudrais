<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Support\UserAiSettingsResolver;
use App\Application\Contracts\StructuredLogger;
use App\Infrastructure\Ai\Prompts\GatekeeperPrompt;

class GatekeeperAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
        private StructuredLogger $logger
    ) {}

    /**
     * @param array<int, string> $playerTags
     * @param string $vaultSynopsis
     * @param string $locationName
     * @param string $locationDesc
     * @param string $playerConcept
     * @return array{accepted:bool, reason:string, penalty_points:int}
     */
    public function evaluate(string $userMessage, array $playerTags = [], string $vaultSynopsis = '', string $locationName = '', string $locationDesc = '', ?string $userId = null, string $playerConcept = 'Ciudadano común'): array
    {
        $model    = $this->settingsResolver->resolveAgentModel($userId, 'gatekeeper');
        $provider = $this->settingsResolver->resolveAgentProvider($userId, 'gatekeeper');
        $options  = $provider ? ['_provider' => $provider] : [];

        $prompt = GatekeeperPrompt::buildInstruction($playerTags, $vaultSynopsis, $locationName, $locationDesc, $playerConcept);
        $this->logger->info('Gatekeeper: Evaluando mensaje', [
            'playerTags' => $playerTags,
            'playerConcept' => $playerConcept,
            'messageLength' => mb_strlen($userMessage)
        ]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $userMessage]
        ], 0.1, 1200, null, null, null, $options);

        $json = json_decode($response['text'] ?? '{}', true);

        return [
            'accepted' => (bool) ($json['accepted'] ?? true),
            'reason' => (string) ($json['reason'] ?? ($json['accepted'] === false ? 'Rechazado por Gatekeeper' : '')),
            'penalty_points' => (int) ($json['penalty_points'] ?? 0)
        ];
    }
}
