<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AgentGateway;
use App\Domain\Scene\Activity;
use RuntimeException;

final readonly class LaravelAgentGateway implements AgentGateway
{
    public function __construct(
        private SimpleSceneWriterAgent $simpleWriterAgent,
        private ComplexSceneWriterAgent $complexWriterAgent,
    ) {
    }

    public function generateSceneTurn(Activity $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array
    {
        if ($sceneType === 'simple') {
            return $this->simpleWriterAgent->generate($scene, $context, $userMessage, $mode, $onChunk, $userId);
        }

        if ($sceneType === 'complex') {
            return $this->complexWriterAgent->generate($scene, $context, $userMessage, $mode, $onChunk, $userId);
        }

        throw new RuntimeException("Activity type '{$sceneType}' is not fully implemented in the agent gateway yet.");
    }
}
