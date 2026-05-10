<?php

namespace App\Application\Agents;

interface QuestScaffolderAgent
{
    /**
     * @return array<string, mixed>
     */
    public function generate(string $prompt, ?string $userId = null): array;
}
