<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Prompts\VoiceInterviewTurnPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

/**
 * Agente unificado de turno de entrevista de voz.
 *
 * Hace en UN solo viaje LLM lo que antes hacían dos agentes separados:
 *  - VoiceGatekeeperAgent: extrae valores del transcript.
 *  - VoiceInterviewerAgent: genera la siguiente pregunta hablada.
 *
 * Retorna: {response_type, extracted, next_question}
 *
 * VoiceAnalystAgent (PHP puro) sigue siendo el árbitro de completitud — no se elimina.
 */
class VoiceInterviewTurnAgent
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $settingsResolver,
    ) {}

    /**
     * Procesa un turno de entrevista de voz en una sola llamada LLM.
     *
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string,field_type:string,options:array}> $allFields
     * @param array<string,string> $alreadyExtracted
     * @param array<array{role:string,content:string}> $conversationHistory
     * @return array{response_type:string, extracted:array<string,string>, next_question:string|null}
     */
    public function processTurn(
        string $transcript,
        array $allFields,
        array $alreadyExtracted,
        array $conversationHistory = [],
        ?string $playerId = null,
    ): array {
        $lastQuestion = $this->extractLastQuestion($conversationHistory);

        Log::debug('[VoiceInterviewTurnAgent@processTurn] Inicio', [
            'transcript_length'    => mb_strlen($transcript),
            'fields_count'         => count($allFields),
            'already_extracted'    => count($alreadyExtracted),
            'history_turns'        => count($conversationHistory),
            'last_question_length' => mb_strlen($lastQuestion),
        ]);

        $systemPrompt = $this->resolveSystemPrompt($allFields, $alreadyExtracted, $lastQuestion, $conversationHistory);

        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'interview_gatekeeper');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'interview_gatekeeper');
        $options  = $provider ? ['_provider' => $provider] : [];

        Log::info('[VoiceInterviewTurnAgent@processTurn] Llamando AI', ['model' => $model]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $transcript],
        ], 0.2, 700, null, null, null, $options);

        $result = $this->parseResponse($response['text'] ?? '');

        Log::info('[VoiceInterviewTurnAgent@processTurn] Turno completado', [
            'response_type'   => $result['response_type'],
            'extracted_count' => count($result['extracted']),
            'has_next'        => $result['next_question'] !== null,
        ]);

        return $result;
    }

    private function resolveSystemPrompt(
        array $allFields,
        array $alreadyExtracted,
        string $lastQuestion,
        array $conversationHistory,
    ): string {
        $globalTemplate = AiPromptTemplate::getBody('voice_interview_turn', '');

        if ($globalTemplate !== '') {
            Log::debug('[VoiceInterviewTurnAgent] Usando template global de DB');

            $globalTemplate = str_replace('{fields_json}',     json_encode($allFields, JSON_UNESCAPED_UNICODE),       $globalTemplate);
            $globalTemplate = str_replace('{extracted_json}',  json_encode($alreadyExtracted, JSON_UNESCAPED_UNICODE), $globalTemplate);
            $globalTemplate = str_replace('{last_question}',   $lastQuestion,                                          $globalTemplate);

            return $globalTemplate;
        }

        return VoiceInterviewTurnPrompt::getFallback(
            $allFields,
            $alreadyExtracted,
            $lastQuestion,
            $conversationHistory,
        );
    }

    /**
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
     * @return array{response_type:string, extracted:array<string,string>, next_question:string|null}
     */
    private function parseResponse(string $raw): array
    {
        $clean   = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean   = preg_replace('/\s*```$/m', '', $clean ?? $raw);
        $decoded = json_decode(trim($clean ?? $raw), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[VoiceInterviewTurnAgent@parseResponse] JSON inválido — fallback vacío', [
                'raw'   => mb_substr($raw, 0, 300),
                'error' => json_last_error_msg(),
            ]);
            return ['response_type' => 'answer', 'extracted' => [], 'next_question' => null];
        }

        $responseType = in_array($decoded['response_type'] ?? '', ['answer', 'off_topic'], true)
            ? $decoded['response_type']
            : 'answer';

        $extracted    = [];
        $nextQuestion = null;

        if ($responseType === 'answer') {
            if (is_array($decoded['extracted'] ?? null)) {
                foreach ($decoded['extracted'] as $key => $value) {
                    $strVal = is_array($value)
                        ? implode(', ', array_filter($value, 'is_scalar'))
                        : (string) $value;

                    if (mb_strlen(trim($strVal)) >= 1) {
                        $extracted[(string) $key] = trim($strVal);
                    }
                }
            }

            $rawQuestion = $decoded['next_question'] ?? null;
            if (is_string($rawQuestion) && mb_strlen(trim($rawQuestion)) > 0) {
                $nextQuestion = trim($rawQuestion);
            }
        }

        return [
            'response_type' => $responseType,
            'extracted'     => $extracted,
            'next_question' => $nextQuestion,
        ];
    }
}
