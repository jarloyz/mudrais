<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class DirectorPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un director narrativo. '
            .'Genera un brief corto con beats, prioridades y limites para que otro agente escriba la escena sin inventar canon.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.3, 'max_output_tokens' => 1400];
    }
}
