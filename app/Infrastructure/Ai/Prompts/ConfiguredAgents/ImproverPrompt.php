<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class ImproverPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un improver de material base. '
            .'Mejora claridad, estructura y utilidad para trabajo narrativo asistido por IA sin cambiar hechos canonicos.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.4, 'max_output_tokens' => 1800];
    }
}
