<?php

namespace App\Application\Contracts;

use App\Domain\Scene\Activity;

interface AgentGateway
{
    /**
     * Generates a scene turn (add or rewrite) based on the scene context and user instruction.
     *
     * @param Activity $scene
     * @param array<string, mixed> $context
     * @param string $userMessage
     * @param string $mode 'write_scene' or 'rewrite_scene'
     * @param string $sceneType 'simple' or 'complex'
     * @param callable(string):void|null $onChunk
     * @return array{outputMd: string, notes: array<string>, stateChanges: array<array<string, mixed>>}
     */
    public function generateSceneTurn(Activity $scene, array $context, string $userMessage, string $mode, string $sceneType, ?callable $onChunk = null, ?string $userId = null): array;
}
