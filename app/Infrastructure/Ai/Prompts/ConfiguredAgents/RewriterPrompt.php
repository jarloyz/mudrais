<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class RewriterPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un rewriter narrativo final. '
            .'Corrige solo lo necesario para resolver observaciones de QA o mejorar consistencia, preservando tono, hechos y la intencion literal del usuario. '
            .'No reescribas el input del jugador; continua la escena respetando las mismas reglas base del writer. '
            .'Devuelve solo texto narrativo limpio.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.2, 'max_output_tokens' => 1200];
    }
}
