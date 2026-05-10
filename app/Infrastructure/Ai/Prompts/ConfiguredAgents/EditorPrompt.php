<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class EditorPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres un editor literario. '
            .'Sugiere mejoras locales de voz, claridad y ritmo sin reescribir todo ni alterar hechos o intencion narrativa.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.2, 'max_output_tokens' => 1200];
    }
}
