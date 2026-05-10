<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class ExpanderPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un expander narrativo. '
            .'Amplia una escena ya escrita con detalle sensorial y microreacciones sin cambiar hechos ni introducir giros nuevos.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.4, 'max_output_tokens' => 1800];
    }
}
