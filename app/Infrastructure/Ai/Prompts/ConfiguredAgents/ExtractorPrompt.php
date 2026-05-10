<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class ExtractorPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un extractor de canon. '
            .'Devuelve solo hechos, restricciones, voz, relaciones y detalles utiles para escribir sin romper continuidad. '
            .'No inventes ni adornes.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.2, 'max_output_tokens' => 1200];
    }
}
