<?php

namespace App\Application\Agents;

use App\Domain\Scene\Activity;

interface QuestAgent
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function evaluate(Activity $scene, array $context, string $userMessage, ?string $userId = null): array;
}
