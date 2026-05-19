<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Prompts\InterviewGatekeeperPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class InterviewGatekeeperAgent
{
    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
        private ContentSafetyAgent $safetyAgent,
    ) {}

    private const VALID_RESPONSE_TYPES = ['answer', 'question', 'off_topic'];

    /**
     * Traduce la respuesta del usuario al inglés, detecta spam, clasifica el tipo de respuesta y extrae field values.
     *
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $fields
     * @param array<string,string> $alreadyExtracted
     * @return array{english_text:string, is_spam:bool, response_type:string, question_field:string|null, explanation:string|null, extracted:array<string,string>}
     */
    public function process(
        string $userAnswer,
        array $fields,
        array $alreadyExtracted,
        ?string $playerId = null,
        ?Archetype $archetype = null,
    ): array {
        Log::debug('[InterviewGatekeeperAgent@process] Inicio', [
            'answer_length'    => mb_strlen($userAnswer),
            'fields_count'     => count($fields),
            'extracted_count'  => count($alreadyExtracted),
            'archetype_id'     => $archetype?->id,
        ]);

        $safetyResult = $this->safetyAgent->checkForInterview($userAnswer, $playerId);
        $isSpam       = ! $safetyResult['is_safe'];

        Log::debug('[InterviewGatekeeperAgent@process] Safety check completado', [
            'is_spam'         => $isSpam,
            'is_manipulation' => $safetyResult['is_manipulation'],
        ]);

        // Manipulación detectada por el guard — cortocircuitar antes de la llamada LLM de extracción
        if ($safetyResult['is_manipulation']) {
            Log::info('[InterviewGatekeeperAgent@process] Manipulación detectada por guard — LLM omitido', [
                'player_id' => $playerId,
            ]);
            return [
                'english_text'   => $userAnswer,
                'is_spam'        => false,
                'response_type'  => 'manipulation',
                'question_field' => null,
                'explanation'    => null,
                'extracted'      => [],
            ];
        }

        $systemPrompt = $this->resolveSystemPrompt($archetype, $fields, $alreadyExtracted);

        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'interview_gatekeeper');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'interview_gatekeeper');
        $options  = $provider ? ['_provider' => $provider] : [];

        Log::info('[InterviewGatekeeperAgent@process] Llamando AI', [
            'model' => $model,
        ]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userAnswer],
        ], 0.1, 800, null, null, null, $options);

        $result = $this->parseResponse($response['text'] ?? '', $userAnswer);

        Log::info('[InterviewGatekeeperAgent@process] Extracción completada', [
            'extracted_count' => count($result['extracted']),
            'is_spam'         => $isSpam,
            'response_type'   => $result['response_type'],
        ]);

        return [
            'english_text'   => $result['english_text'],
            'is_spam'        => $isSpam,
            'response_type'  => $result['response_type'],
            'question_field' => $result['question_field'],
            'explanation'    => $result['explanation'],
            'extracted'      => $result['extracted'],
        ];
    }

    /**
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $fields
     * @param array<string,string> $alreadyExtracted
     */
    private function resolveSystemPrompt(?Archetype $archetype, array $fields, array $alreadyExtracted): string
    {
        // 1. Override por arquetipo (más específico)
        $archetypePrompt = $archetype?->getPromptFor('interview_gatekeeper');
        if ($archetypePrompt !== null) {
            $injected = $this->injectFieldContext($archetypePrompt, $fields, $alreadyExtracted);

            // Verificar que los placeholders fueron reemplazados; si no, caer al fallback PHP
            if (! str_contains($injected, '{fields_json}') && ! str_contains($injected, '{extracted_json}')) {
                Log::debug('[InterviewGatekeeperAgent] Usando prompt de arquetipo', ['archetype_id' => $archetype?->id]);
                return $injected;
            }

            Log::warning('[InterviewGatekeeperAgent] Placeholders sin reemplazar en prompt de arquetipo — usando PHP fallback', [
                'archetype_id' => $archetype?->id,
            ]);
        }

        // 2. Global editable en DB — también requiere inyección de contexto
        $globalTemplate = AiPromptTemplate::getBody('interview_gatekeeper', '');
        if ($globalTemplate !== '') {
            $injected = $this->injectFieldContext($globalTemplate, $fields, $alreadyExtracted);

            if (! str_contains($injected, '{fields_json}') && ! str_contains($injected, '{extracted_json}')) {
                Log::debug('[InterviewGatekeeperAgent] Usando template global de DB');
                return $injected;
            }

            Log::warning('[InterviewGatekeeperAgent] Placeholders sin reemplazar en template global — usando PHP fallback');
        }

        // 3. PHP fallback (siempre funciona, inyecta los campos inline)
        Log::debug('[InterviewGatekeeperAgent] Usando fallback PHP');
        return InterviewGatekeeperPrompt::getFallback($fields, $alreadyExtracted);
    }

    /**
     * Inyecta el contexto de campos en un prompt de arquetipo que no los incluya.
     *
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $fields
     * @param array<string,string> $alreadyExtracted
     */
    private function injectFieldContext(string $prompt, array $fields, array $alreadyExtracted): string
    {
        if (str_contains($prompt, '{fields_json}')) {
            $pendingFields = array_filter(
                $fields,
                fn($f) => ! isset($alreadyExtracted[$f['field_key']]) || trim((string) $alreadyExtracted[$f['field_key']]) === ''
            );
            $prompt = str_replace('{fields_json}', json_encode(array_values($pendingFields), JSON_UNESCAPED_UNICODE), $prompt);
        }

        if (str_contains($prompt, '{extracted_json}')) {
            $prompt = str_replace('{extracted_json}', json_encode($alreadyExtracted, JSON_UNESCAPED_UNICODE), $prompt);
        }

        return $prompt;
    }

    /**
     * @return array{english_text:string, response_type:string, question_field:string|null, extracted:array<string,string>}
     */
    private function parseResponse(string $raw, string $originalAnswer): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean ?? $raw);

        $decoded = json_decode(trim($clean ?? $raw), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[InterviewGatekeeperAgent@parseResponse] JSON inválido, usando fallback', [
                'raw'        => mb_substr($raw, 0, 300),
                'json_error' => json_last_error_msg(),
            ]);

            return [
                'english_text'   => $originalAnswer,
                'response_type'  => 'answer',
                'question_field' => null,
                'explanation'    => null,
                'extracted'      => [],
            ];
        }

        $rawType = $decoded['response_type'] ?? 'answer';
        $responseType = in_array($rawType, self::VALID_RESPONSE_TYPES, true) ? $rawType : 'answer';

        $extracted = is_array($decoded['extracted'] ?? null) ? $decoded['extracted'] : [];

        // Solo filtrar extracciones si la respuesta es una respuesta real
        if ($responseType !== 'answer') {
            $extracted = [];
        } else {
            $extracted = array_filter(
                $extracted,
                fn($v) => is_string($v) && mb_strlen(trim($v)) >= 3
            );
        }

        // question_field y explanation solo son relevantes cuando response_type = 'question'
        $questionField = null;
        $explanation   = null;
        if ($responseType === 'question') {
            $raw_qf = $decoded['question_field'] ?? null;
            $questionField = is_string($raw_qf) && $raw_qf !== '' && $raw_qf !== 'null' ? $raw_qf : null;

            $raw_ex = $decoded['explanation'] ?? null;
            $explanation = is_string($raw_ex) && trim($raw_ex) !== '' && $raw_ex !== 'null' ? trim($raw_ex) : null;
        }

        return [
            'english_text'  => is_string($decoded['english_text'] ?? null) && $decoded['english_text'] !== ''
                ? $decoded['english_text']
                : $originalAnswer,
            'response_type'  => $responseType,
            'question_field' => $questionField,
            'explanation'    => $explanation,
            'extracted'      => $extracted,
        ];
    }
}
