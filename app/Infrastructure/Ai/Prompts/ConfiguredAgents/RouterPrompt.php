<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class RouterPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un router para un vault de historia en Markdown. '
            .'Elige solo los archivos minimos necesarios, evita lecturas redundantes y responde en JSON utilizable. '
            .'No inventes canon ni preguntes si una entidad ya aparece como existente.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.2, 'max_output_tokens' => 1200];
    }
}
