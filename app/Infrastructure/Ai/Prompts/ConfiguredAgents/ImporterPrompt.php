<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class ImporterPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un importer de canon. '
            .'Convierte material fuente a una estructura util, compacta y consistente, sin inventar hechos no presentes.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.4, 'max_output_tokens' => 1800];
    }
}
