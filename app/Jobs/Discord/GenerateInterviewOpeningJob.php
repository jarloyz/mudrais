<?php

namespace App\Jobs\Discord;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Infrastructure\Ai\Prompts\InterviewOpeningGeneratorPrompt;
use App\Jobs\Voice\GenerateVoiceAssetsJob;
use App\Support\UserAiSettingsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera y almacena la pregunta de apertura de entrevista para un arquetipo.
 * Se dispara automáticamente cuando cambian los mutadores de registro del arquetipo.
 * Implementa ShouldBeUnique para evitar múltiples generaciones simultáneas.
 */
class GenerateInterviewOpeningJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 2;

    public function __construct(
        public readonly string $archetypeId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->archetypeId;
    }

    public function handle(AiChatGateway $gateway, UserAiSettingsResolver $resolver): void
    {
        Log::info('[GenerateInterviewOpeningJob] Inicio', ['archetype_id' => $this->archetypeId]);

        $archetype = Archetype::find($this->archetypeId);
        if (! $archetype) {
            Log::warning('[GenerateInterviewOpeningJob] Arquetipo no encontrado', ['archetype_id' => $this->archetypeId]);
            return;
        }

        $aiFields = $archetype->mutators()
            ->where('context', 'registration')
            ->whereIn('field_type', InterviewerAgent::AI_FIELD_TYPES)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($m) => [
                'field_key'  => $m->field_key,
                'field_label' => $m->field_label,
                'hint'        => $m->field_placeholder ?? '',
            ])
            ->toArray();

        if (empty($aiFields)) {
            Log::info('[GenerateInterviewOpeningJob] Sin campos AI — sin pregunta que generar', [
                'archetype_id' => $this->archetypeId,
            ]);
            return;
        }

        Log::debug('[GenerateInterviewOpeningJob] Campos AI para apertura', [
            'archetype_id' => $this->archetypeId,
            'field_count'  => count($aiFields),
            'field_keys'   => array_column($aiFields, 'field_key'),
        ]);

        $model = $resolver->resolveExplicitAgentModel(null, 'interview_opening');

        if (! $model) {
            Log::warning('[GenerateInterviewOpeningJob] Sin modelo configurado para el agente interview_opening — configura el modelo en Filament → Settings → Agentes → Interview Opening', [
                'archetype_id' => $this->archetypeId,
            ]);
            return;
        }

        $provider     = $resolver->resolveAgentProvider(null, 'interview_opening');
        $reasoning    = $resolver->resolveAgentReasoning(null, 'interview_opening');
        $budgetTokens = $resolver->resolveAgentBudgetTokens(null, 'interview_opening');

        $options = $provider ? ['_provider' => $provider] : [];
        if ($reasoning) {
            $options['reasoning'] = ['enabled' => true, 'budget_tokens' => $budgetTokens];
        }

        // ── Versión en español (para Discord display) ────────────────────────
        Log::info('[GenerateInterviewOpeningJob] Llamando AI — versión español', [
            'archetype_id' => $this->archetypeId,
            'model'        => $model,
        ]);

        $responseEs = $gateway->chat($model, [
            ['role' => 'system', 'content' => InterviewOpeningGeneratorPrompt::getFallback($aiFields, 'Spanish')],
            ['role' => 'user',   'content' => 'Generate the opening question now.'],
        ], 0.4, 400, null, null, null, $options);

        $questionEs = $this->parseResponse($responseEs['text'] ?? '');

        if (! $questionEs) {
            Log::warning('[GenerateInterviewOpeningJob] No se pudo parsear pregunta de apertura (español)', [
                'archetype_id' => $this->archetypeId,
                'raw'          => mb_substr($responseEs['text'] ?? '', 0, 300),
            ]);
            return;
        }

        $archetype->prompts()->updateOrCreate(
            ['agent_type' => 'interview_opening'],
            ['system_prompt' => $questionEs]
        );

        Log::info('[GenerateInterviewOpeningJob] Pregunta de apertura (español) guardada', [
            'archetype_id' => $this->archetypeId,
            'length'       => mb_strlen($questionEs),
        ]);

        // ── Versión en inglés (para TTS — Speechmatics solo tiene voces en inglés) ─
        Log::info('[GenerateInterviewOpeningJob] Llamando AI — versión inglés para TTS', [
            'archetype_id' => $this->archetypeId,
            'model'        => $model,
        ]);

        try {
            $responseEn = $gateway->chat($model, [
                ['role' => 'system', 'content' => InterviewOpeningGeneratorPrompt::getFallback($aiFields, 'English')],
                ['role' => 'user',   'content' => 'Generate the opening question now.'],
            ], 0.4, 400, null, null, null, $options);

            $questionEn = $this->parseResponse($responseEn['text'] ?? '');

            if ($questionEn) {
                $archetype->prompts()->updateOrCreate(
                    ['agent_type' => 'interview_opening_en'],
                    ['system_prompt' => $questionEn]
                );

                Log::info('[GenerateInterviewOpeningJob] Pregunta de apertura (inglés TTS) guardada', [
                    'archetype_id' => $this->archetypeId,
                    'length'       => mb_strlen($questionEn),
                ]);
            } else {
                Log::warning('[GenerateInterviewOpeningJob] No se pudo parsear versión inglés — VoiceAssetService usará traducción automática', [
                    'archetype_id' => $this->archetypeId,
                    'raw'          => mb_substr($responseEn['text'] ?? '', 0, 300),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[GenerateInterviewOpeningJob] Error generando versión inglés — VoiceAssetService usará traducción automática', [
                'archetype_id' => $this->archetypeId,
                'error'        => $e->getMessage(),
            ]);
        }

        // Disparar generación de audios TTS pre-cacheados (apertura + fillers contextuales).
        GenerateVoiceAssetsJob::dispatch($this->archetypeId);

        Log::info('[GenerateInterviewOpeningJob] GenerateVoiceAssetsJob despachado', [
            'archetype_id' => $this->archetypeId,
        ]);
    }

    private function parseResponse(string $raw): ?string
    {
        $clean   = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean   = preg_replace('/\s*```$/m', '', $clean ?? $raw);
        $decoded = json_decode(trim($clean ?? $raw), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $question = $decoded['opening_question'] ?? null;

        return is_string($question) && mb_strlen(trim($question)) >= 10 ? trim($question) : null;
    }
}
