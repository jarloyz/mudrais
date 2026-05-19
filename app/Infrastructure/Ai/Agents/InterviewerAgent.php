<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Infrastructure\Ai\Prompts\InterviewerPrompt;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class InterviewerAgent
{
    /**
     * Tipos de campo que se procesan vía entrevista IA (texto libre).
     * Tipos fuera de este array (select, range, boolean) se manejan con modal de formulario.
     */
    public const AI_FIELD_TYPES = ['text', 'text_short', 'text_long'];

    private const DEFAULT_FIELDS = [
        ['field_key' => 'preferences',  'field_label' => 'Preferencias / Favoritos', 'is_required' => true,  'hint' => 'Géneros, tropos, temáticas',     'field_type' => 'text', 'options' => []],
        ['field_key' => 'style',        'field_label' => 'Estilo de Juego',           'is_required' => true,  'hint' => 'Persona, tono, ritmo narrativo', 'field_type' => 'text', 'options' => []],
        ['field_key' => 'red_lines',    'field_label' => 'Límites Absolutos',         'is_required' => false, 'hint' => 'Temas que jamás quieres ver',    'field_type' => 'text', 'options' => []],
        ['field_key' => 'yellow_lines', 'field_label' => 'Temas a Evitar',            'is_required' => false, 'hint' => 'Temas que prefieres no tocar',   'field_type' => 'text', 'options' => []],
        ['field_key' => 'schedule_raw', 'field_label' => 'Disponibilidad',            'is_required' => false, 'hint' => 'Días y horarios aproximados',    'field_type' => 'text', 'options' => []],
    ];

    public function __construct(
        private AiChatGateway $gateway,
        private UserAiSettingsResolver $settingsResolver,
        private ArchetypeMutatorService $mutatorService,
    ) {}

    /**
     * Formula UNA pregunta conversacional para obtener los campos que faltan.
     *
     * @param list<string>         $missingFieldKeys   Field keys que faltan según el Analyst
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $allFields
     * @param array<array{role:string,content:string}> $conversationHistory
     * @param ?string              $archetypeId
     * @param ?string              $playerId
     */
    public function formulateQuestion(
        array $missingFieldKeys,
        array $allFields,
        array $conversationHistory,
        ?string $archetypeId = null,
        ?string $playerId = null,
    ): string {
        Log::debug('[InterviewerAgent@formulateQuestion] Inicio', [
            'missing_count'  => count($missingFieldKeys),
            'history_turns'  => count($conversationHistory),
            'archetype_id'   => $archetypeId,
        ]);

        // Filtrar allFields para incluir solo los que faltan, en orden de prioridad
        $missingFields = array_values(array_filter(
            $allFields,
            fn($f) => in_array($f['field_key'], $missingFieldKeys, true)
        ));

        $systemPrompt = $this->resolveSystemPrompt($archetypeId, $missingFields, $conversationHistory);

        $model    = $this->settingsResolver->resolveAgentModel($playerId, 'interviewer');
        $provider = $this->settingsResolver->resolveAgentProvider($playerId, 'interviewer');
        $options  = $provider ? ['_provider' => $provider] : [];

        Log::info('[InterviewerAgent@formulateQuestion] Llamando AI', [
            'model'   => $model,
            'missing' => $missingFieldKeys,
        ]);

        $response = $this->gateway->chat($model, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => 'Formula la siguiente pregunta.'],
        ], 0.4, 300, null, null, null, $options);

        $question = $this->parseQuestion($response['text'] ?? '');

        Log::info('[InterviewerAgent@formulateQuestion] Pregunta generada', [
            'question_length' => mb_strlen($question),
        ]);

        return $question;
    }

    /**
     * Construye un array de Discord embed fields a partir de los campos extraídos.
     *
     * @param array<string,string> $extractedFields
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $fields
     * @return array<int,array{name:string,value:string,inline:bool}>
     */
    public function buildEmbedFields(array $extractedFields, array $fields): array
    {
        $embedFields = [];

        foreach ($fields as $field) {
            $value = $extractedFields[$field['field_key']] ?? null;
            if (empty($value)) {
                continue;
            }
            $embedFields[] = [
                'name'   => $field['field_label'],
                'value'  => mb_substr((string) $value, 0, 1024),
                'inline' => false,
            ];
        }

        return $embedFields;
    }

    /**
     * Carga el schema de campos desde los mutadores del arquetipo o usa los defaults.
     *
     * @return array<array{field_key:string,field_label:string,is_required:bool,hint:string}>
     */
    public function resolveFields(?string $archetypeId, string $context = 'registration'): array
    {
        if (! $archetypeId) {
            return self::DEFAULT_FIELDS;
        }

        $mutators = $this->mutatorService->getFieldsForContext($archetypeId, $context);

        if ($mutators->isEmpty()) {
            return self::DEFAULT_FIELDS;
        }

        return $mutators->map(fn($m) => [
            'field_key'   => $m->field_key,
            'field_label' => $m->field_label,
            'is_required' => (bool) $m->is_required,
            'hint'        => $m->field_placeholder ?? '',
            'field_type'  => $m->field_type ?? 'text',
            'options'     => is_array($m->options) ? $m->options : [],
        ])->toArray();
    }

    /**
     * @param list<array{field_key:string,field_label:string,is_required:bool,hint:string}> $missingFields
     * @param array<array{role:string,content:string}> $conversationHistory
     */
    private function resolveSystemPrompt(
        ?string $archetypeId,
        array $missingFields,
        array $conversationHistory,
    ): string {
        $phpFallback = InterviewerPrompt::getFallback($missingFields, $conversationHistory);

        if ($archetypeId) {
            $archetype   = Archetype::find($archetypeId);
            $personality = $archetype?->getPromptFor('interviewer');

            if ($personality !== null) {
                Log::debug('[InterviewerAgent] Usando prompt de arquetipo', ['archetype_id' => $archetypeId]);
                return $this->injectContext($personality, $missingFields, $conversationHistory);
            }
        }

        // Global editable en DB
        $globalTemplate = AiPromptTemplate::getBody('interviewer_question', '');

        if ($globalTemplate !== '') {
            return $this->injectContext($globalTemplate, $missingFields, $conversationHistory);
        }

        return $phpFallback;
    }

    /**
     * @param list<array{field_key:string,field_label:string,is_required:bool,hint:string}> $missingFields
     * @param array<array{role:string,content:string}> $conversationHistory
     */
    private function injectContext(string $template, array $missingFields, array $conversationHistory): string
    {
        if (str_contains($template, '{missing_fields_json}')) {
            $template = str_replace(
                '{missing_fields_json}',
                json_encode($missingFields, JSON_UNESCAPED_UNICODE),
                $template
            );
        }

        if (str_contains($template, '{conversation_history}')) {
            $historyLines = [];
            foreach (array_slice($conversationHistory, -8) as $msg) {
                $role          = $msg['role'] === 'assistant' ? 'Entrevistador' : 'Usuario';
                $historyLines[] = "{$role}: " . mb_substr((string) $msg['content'], 0, 300);
            }
            $template = str_replace('{conversation_history}', implode("\n", $historyLines), $template);
        }

        return $template;
    }

    private function parseQuestion(string $raw): string
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean ?? $raw);

        $decoded = json_decode(trim($clean ?? $raw), true);

        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['next_question'])) {
            $q = trim((string) $decoded['next_question']);
            if ($q !== '') {
                return $q;
            }
        }

        // Si no hay JSON válido, devolver el texto crudo (el LLM puede haber respondido directamente)
        $plainText = trim($raw);
        if ($plainText !== '') {
            Log::debug('[InterviewerAgent@parseQuestion] Respuesta no-JSON, usando texto crudo');
            return $plainText;
        }

        Log::warning('[InterviewerAgent@parseQuestion] Respuesta vacía');
        return '';
    }
}
