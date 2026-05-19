<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Prompts\VoiceInterviewerPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

/**
 * Variante de voz del InterviewerAgent.
 *
 * Diferencias clave respecto al original de texto:
 * - Acepta TODOS los tipos de campo (select, multiselect, range, boolean, text).
 * - Genera preguntas cortas en inglés hablado (≤ 30 palabras, sin markdown).
 * - Para campos select/multiselect: nombra las opciones verbalmente.
 * - Para campos range/boolean: describe la escala o las opciones.
 * - No requiere ArchetypeMutatorService — recibe los campos ya resueltos.
 */
class VoiceInterviewerAgent
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Genera UNA pregunta hablada en inglés para los campos faltantes.
     *
     * @param list<string> $missingFieldKeys
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string,field_type:string,options:array}> $allFields
     * @param array<array{role:string,content:string}> $conversationHistory
     */
    public function formulateQuestion(
        array $missingFieldKeys,
        array $allFields,
        array $conversationHistory,
        ?string $playerId = null,
    ): string {
        Log::debug('[VoiceInterviewerAgent@formulateQuestion] Inicio', [
            'missing_count' => count($missingFieldKeys),
            'history_turns' => count($conversationHistory),
        ]);

        // Filter to only missing fields, required first
        $missingFields = array_values(array_filter(
            $allFields,
            fn($f) => in_array($f['field_key'], $missingFieldKeys, true)
        ));

        usort($missingFields, fn($a, $b) => ($b['is_required'] ? 1 : 0) <=> ($a['is_required'] ? 1 : 0));

        $systemPrompt = $this->resolveSystemPrompt($missingFields, $conversationHistory);

        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'interviewer');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'interviewer');
        $options  = $provider ? ['_provider' => $provider] : [];

        Log::info('[VoiceInterviewerAgent@formulateQuestion] Llamando AI', [
            'model'   => $model,
            'missing' => $missingFieldKeys,
        ]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => 'Ask the next interview question.'],
        ], 0.4, 150, null, null, null, $options);

        $question = $this->parseQuestion($response['text'] ?? '');

        Log::info('[VoiceInterviewerAgent@formulateQuestion] Pregunta generada', [
            'length' => mb_strlen($question),
        ]);

        return $question;
    }

    private function resolveSystemPrompt(array $missingFields, array $conversationHistory): string
    {
        $globalTemplate = AiPromptTemplate::getBody('voice_interviewer_question', '');

        if ($globalTemplate !== '') {
            Log::debug('[VoiceInterviewerAgent] Usando template global de DB');
            return $globalTemplate;
        }

        return VoiceInterviewerPrompt::getFallback($missingFields, $conversationHistory);
    }

    private function parseQuestion(string $raw): string
    {
        $clean   = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean   = preg_replace('/\s*```$/m', '', $clean ?? $raw);
        $decoded = json_decode(trim($clean ?? $raw), true);

        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['next_question'])) {
            $q = trim((string) $decoded['next_question']);
            if ($q !== '') {
                return $q;
            }
        }

        $plain = trim($raw);
        if ($plain !== '') {
            Log::debug('[VoiceInterviewerAgent@parseQuestion] Respuesta no-JSON, usando texto crudo');
            return $plain;
        }

        Log::warning('[VoiceInterviewerAgent@parseQuestion] Respuesta vacía');
        return '';
    }
}
