<?php

namespace App\Infrastructure\Agents;

use App\Application\Contracts\AiChatGateway;
use Illuminate\Support\Facades\Log;

class StoryWriterAgent
{
    private string $model;

    public function __construct(private readonly AiChatGateway $aiChatGateway)
    {
        $this->model = config('services.openrouter.model', 'google/gemini-2.5-pro');
    }

    public function generateDraft(array $context, string $userMessage): string
    {
        Log::channel('agents')->info('StoryWriterAgent: Iniciando redacción del turno', [
            'model'               => $this->model,
            'scene_id'            => $context['scene']->id ?? null,
            'user_message_length' => strlen($userMessage),
        ]);

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemInstruction($context)],
            ['role' => 'user',   'content' => $this->buildPrompt($context, $userMessage)],
        ];

        try {
            $result = $this->aiChatGateway->chat(
                model:           $this->model,
                messages:        $messages,
                temperature:     0.8,
                maxOutputTokens: 8192,
            );

            $text = $result['text'] ?? '';

            Log::channel('agents')->info('StoryWriterAgent: Turno redactado con éxito', [
                'total_tokens' => $result['usage']['total_tokens'] ?? null,
            ]);

            return $text;
        } catch (\Exception $e) {
            Log::channel('agents')->error('StoryWriterAgent: Error al generar turno', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildSystemInstruction(array $context): string
    {
        $vault = $context['vault'] ?? null;
        $globalInstructions = $vault ? ($vault->agent_instructions['writer'] ?? '') : '';

        return "Eres un narrador experto de historias interactivas (tipo rol de texto).
Sigue estrictamente las reglas del mundo y la personalidad de los personajes descritos en el contexto.

Reglas del Mundo/Bóveda:
{$globalInstructions}

Mantén un tono coherente con la escena actual. Responde en el formato solicitado y asegúrate de avanzar la trama basándote en la última acción del usuario.";
    }

    private function buildPrompt(array $context, string $userMessage): string
    {
        $prompt = "--- CONTEXTO ACTUAL DE LA HISTORIA ---\n\n";

        if (isset($context['scene'])) {
            $scene = $context['scene'];
            $prompt .= "ESCENA ACTUAL:\n";
            $prompt .= "Título: {$scene->title}\n";
            $prompt .= "Objetivo: {$scene->objective}\n";
            $prompt .= "Ubicación: ".($scene->location ? $scene->location->name : 'Desconocida')."\n\n";
        }

        if (! empty($context['characters'])) {
            $prompt .= "PERSONAJES PRESENTES:\n";
            foreach ($context['characters'] as $character) {
                $prompt .= "- {$character->name}:\n";
                if (isset($character->active_bullets)) {
                    foreach ($character->active_bullets as $bullet) {
                        $prompt .= "  * {$bullet->content}\n";
                    }
                }
                if (isset($character->runtime_status)) {
                    $prompt .= "  Estado actual:\n";
                    foreach ($character->runtime_status as $status) {
                        $prompt .= "  * {$status->status_key}: {$status->status_text} ({$status->status_value})\n";
                    }
                }
                $prompt .= "\n";
            }
        }

        if (! empty($context['recent_events'])) {
            $prompt .= "EVENTOS RECIENTES (Memoria a corto plazo):\n";
            foreach ($context['recent_events'] as $event) {
                $prompt .= "- [{$event->date_label}] {$event->title}: {$event->brief}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "--- ACCIÓN DEL USUARIO ---\n";
        $prompt .= $userMessage."\n\n";
        $prompt .= "Por favor, describe lo que sucede a continuación, enfocándote en las reacciones lógicas de los personajes y el entorno.";

        return $prompt;
    }
}
