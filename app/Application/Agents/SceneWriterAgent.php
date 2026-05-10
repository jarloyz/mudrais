<?php

namespace App\Application\Agents;

use App\Domain\Scene\Activity;

interface SceneWriterAgent
{
    /**
     * @param Activity $scene
     * @param array<string, mixed> $context
     * @param string $userMessage
     * @param string $mode
     * @param callable(string):void|null $onChunk
     * @return array{outputMd: string, notes: array<string>, stateChanges: array<array<string, mixed>>}
     */
    public function generate(Activity $scene, array $context, string $userMessage, string $mode, ?callable $onChunk = null, ?string $userId = null): array;
}
