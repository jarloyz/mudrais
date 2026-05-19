<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Prompts\VoiceGatekeeperPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

/**
 * Variante de voz del InterviewGatekeeperAgent.
 *
 * Diferencias clave respecto al original de texto:
 * - Extrae TODOS los tipos de campo (select, multiselect, range, boolean, text).
 * - Fuzzy matching para campos SELECT: mapea lenguaje natural a opciones exactas.
 * - Más permisivo: solo "answer" u "off_topic" (sin "question" — voz es bidireccional vía STT).
 * - Sin safety/spam check — el audio no permite inyección de prompts.
 * - Acepta valores cortos (para "no", rangos numéricos, etc.).
 */
class VoiceGatekeeperAgent
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Extrae campos de perfil de un transcript de voz.
     *
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string,field_type:string,options:array}> $fields
     * @param array<string,string> $alreadyExtracted
     * @param array<array{role:string,content:string}> $conversationHistory  Historial previo para extraer la última pregunta.
     * @return array{response_type:string, extracted:array<string,string>}
     */
    public function process(
        string $transcript,
        array $fields,
        array $alreadyExtracted,
        ?string $playerId = null,
        array $conversationHistory = [],
    ): array {
        $lastQuestion = $this->extractLastQuestion($conversationHistory);

        Log::debug('[VoiceGatekeeperAgent@process] Inicio', [
            'transcript_length' => mb_strlen($transcript),
            'fields_count'      => count($fields),
            'extracted_count'   => count($alreadyExtracted),
            'last_question'     => mb_substr($lastQuestion, 0, 80),
        ]);

        $systemPrompt = $this->resolveSystemPrompt($fields, $alreadyExtracted, $lastQuestion);

        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'interview_gatekeeper');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'interview_gatekeeper');
        $options  = $provider ? ['_provider' => $provider] : [];

        Log::info('[VoiceGatekeeperAgent@process] Llamando AI', ['model' => $model]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $transcript],
        ], 0.1, 600, null, null, null, $options);

        $result = $this->parseResponse($response['text'] ?? '');

        Log::info('[VoiceGatekeeperAgent@process] Extracción completada', [
            'extracted_count' => count($result['extracted']),
            'response_type'   => $result['response_type'],
        ]);

        return $result;
    }

    private function resolveSystemPrompt(array $fields, array $alreadyExtracted, string $lastQuestion): string
    {
        $globalTemplate = AiPromptTemplate::getBody('voice_gatekeeper', '');

        if ($globalTemplate !== '') {
            Log::debug('[VoiceGatekeeperAgent] Usando template global de DB');
            $globalTemplate = str_replace('{fields_json}',     json_encode($fields, JSON_UNESCAPED_UNICODE),     $globalTemplate);
            $globalTemplate = str_replace('{extracted_json}',  json_encode($alreadyExtracted, JSON_UNESCAPED_UNICODE), $globalTemplate);
            $globalTemplate = str_replace('{last_question}',   $lastQuestion,                                     $globalTemplate);
            return $globalTemplate;
        }

        return VoiceGatekeeperPrompt::getFallback($fields, $alreadyExtracted, $lastQuestion);
    }

    /**
     * Extrae la última pregunta formulada por el entrevistador del historial.
     *
     * @param array<array{role:string,content:string}> $history
     */
    private function extractLastQuestion(array $history): string
    {
        foreach (array_reverse($history) as $msg) {
            if (($msg['role'] ?? '') === 'assistant') {
                return (string) ($msg['content'] ?? '');
            }
        }
        return '';
    }

    /**
     * @return array{response_type:string, extracted:array<string,string>}
     */
    private function parseResponse(string $raw): array
    {
        $clean   = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean   = preg_replace('/\s*```$/m', '', $clean ?? $raw);
        $decoded = json_decode(trim($clean ?? $raw), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[VoiceGatekeeperAgent@parseResponse] JSON inválido — usando fallback answer vacío', [
                'raw'   => mb_substr($raw, 0, 300),
                'error' => json_last_error_msg(),
            ]);
            return ['response_type' => 'answer', 'extracted' => []];
        }

        $responseType = in_array($decoded['response_type'] ?? '', ['answer', 'off_topic'], true)
            ? $decoded['response_type']
            : 'answer';

        $extracted = [];

        if ($responseType === 'answer' && is_array($decoded['extracted'] ?? null)) {
            foreach ($decoded['extracted'] as $key => $value) {
                // Arrays (e.g. multiselect LLM returned array) → comma-separated string
                $strVal = is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : (string) $value;
                if (mb_strlen(trim($strVal)) >= 1) {
                    $extracted[(string) $key] = trim($strVal);
                }
            }
        }

        return ['response_type' => $responseType, 'extracted' => $extracted];
    }
}
