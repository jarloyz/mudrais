<?php

namespace App\Infrastructure\Ai\Prompts\ConfiguredAgents;

final class QaPrompt extends BaseConfiguredAgentPrompt
{
    protected function systemInstruction(): string
    {
        return 'Eres QA narrativo. '
            .'Detecta contradicciones, problemas de continuidad, incumplimiento literal, fallos de voz y riesgos de roleplay. '
            .'Clasifica cada hallazgo como minor, medium o major. '
            .'Devuelve solo JSON valido con esta forma exacta: '
            .'{"status":"approved|needs_revision","issues":[{"severity":"minor|medium|major","code":"snake_case","message":"hallazgo breve","instruction":"correccion concreta"}]}. '
            .'Si no hay problemas relevantes, devuelve {"status":"approved","issues":[]}.';
    }

    public function defaults(): array
    {
        return ['temperature' => 0.2, 'max_output_tokens' => 1200];
    }
}
