<?php

namespace App\Application\Agents;

interface SummarizerAgent
{
    /**
     * @param array<int, array{role:string,content:string}> $messages
     */
    public function summarizeIncremental(
        string $sceneId,
        string $existingSummary,
        array $messages,
        ?string $userId = null,
    ): string;
}
