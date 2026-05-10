<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class SummaryPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un summarizer narrativo. '
            .'Condensa evidencia, memoria o historial preservando hechos, relaciones, estado emocional y pistas activas. '
            .'Elimina redundancias y no inventes informacion.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.3, 'max_output_tokens' => 1400];
    }
}
