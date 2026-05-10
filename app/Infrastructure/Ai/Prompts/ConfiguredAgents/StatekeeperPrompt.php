<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class StatekeeperPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un statekeeper. '
            .'Extrae solo cambios de estado explicitos y utiles: heridas, energia, alertas, inventario o estado del entorno. '
            .'No inventes numeros ni hechos.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.2, 'max_output_tokens' => 1200];
    }
}
