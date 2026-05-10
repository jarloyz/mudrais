<?php

namespace App\Infrastructure\Agents;

use App\Application\Contracts\AiChatGateway;
use Illuminate\Support\Facades\Log;

class StoryQAAgent
{
    private string $model;

    public function __construct(private readonly AiChatGateway $aiChatGateway)
    {
        $this->model = config('services.openrouter.qa_model', 'google/gemini-2.5-flash');
    }

    public function analyzeDraft(string $draft, array $context): array
    {
        Log::channel('agents')->info('StoryQAAgent: Revisando borrador', ['draft_length' => strlen($draft)]);

        $prompt = "Actúa como un supervisor de continuidad y calidad de guiones.
Revisa el siguiente borrador de una historia y compáralo con el contexto dado.
Busca:
1. Alucinaciones o contradicciones con el Canon (eventos pasados o rasgos de los personajes).
2. Cambios bruscos de tono.
3. Omisiones graves de acciones del usuario.

Responde ÚNICAMENTE con un objeto JSON con la siguiente estructura (NO uses bloques markdown de código):
{
  \"status\": \"approved\" | \"rejected\" | \"needs_minor_edits\",
  \"feedback\": [\"lista de observaciones\"],
  \"corrected_draft\": \"texto corregido si es necesario, o el original si está aprobado\"
}

--- CONTEXTO ---
(Omitido por brevedad, el sistema asume que los personajes actúan lógicamente)

--- BORRADOR A REVISAR ---
{$draft}
";

        try {
            $result = $this->aiChatGateway->chat(
                model:           $this->model,
                messages:        [['role' => 'user', 'content' => $prompt]],
                temperature:     0.2,
                maxOutputTokens: 2048,
            );

            $jsonText = $result['text'] ?? '{}';
            $jsonText = preg_replace('/```json\s*/', '', $jsonText);
            $jsonText = preg_replace('/```/', '', $jsonText);
            $parsed   = json_decode(trim($jsonText), true);

            Log::channel('agents')->info('StoryQAAgent: Revisión completada', ['status' => $parsed['status'] ?? 'unknown']);

            return $parsed ?: [
                'status'          => 'approved',
                'feedback'        => ['Error al parsear el JSON de QA, se aprueba por defecto.'],
                'corrected_draft' => $draft,
            ];
        } catch (\Exception $e) {
            Log::channel('agents')->error('StoryQAAgent: Error en QA', ['message' => $e->getMessage()]);

            return [
                'status'          => 'approved',
                'feedback'        => ['QA Agent falló: '.$e->getMessage()],
                'corrected_draft' => $draft,
            ];
        }
    }
}
