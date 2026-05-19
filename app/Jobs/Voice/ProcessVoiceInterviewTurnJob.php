<?php

namespace App\Jobs\Voice;

use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Infrastructure\Ai\Agents\VoiceAnalystAgent;
use App\Infrastructure\Ai\Agents\VoiceInterviewTurnAgent;
use App\Services\Voice\VoiceInterviewSessionManager;
use App\Services\Voice\VoiceTextTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta un turno de entrevista de voz.
 *
 * Usa el agente unificado VoiceInterviewTurnAgent en lugar de dos llamadas LLM separadas.
 * VoiceAnalystAgent (PHP puro) sigue siendo el árbitro de completitud.
 * InterviewerAgent se mantiene solo para resolveFields().
 */
class ProcessVoiceInterviewTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 2;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $transcript,
        public readonly string $discordId,
    ) {
        $this->onQueue('voice');
    }

    public function handle(
        VoiceInterviewTurnAgent $turnAgent,
        VoiceAnalystAgent $analyst,
        InterviewerAgent $interviewer,
        VoiceInterviewSessionManager $sessionManager,
        VoiceTextTranslator $translator,
    ): void {
        Log::info('[ProcessVoiceInterviewTurnJob] Inicio', [
            'session_id' => $this->sessionId,
            'discord_id' => $this->discordId,
            'transcript' => mb_substr($this->transcript, 0, 80),
        ]);

        $state = $sessionManager->getSession($this->sessionId);

        if ($state === null) {
            Log::warning('[ProcessVoiceInterviewTurnJob] Sesión no encontrada o expirada', [
                'session_id' => $this->sessionId,
            ]);
            return;
        }

        App::setLocale($state['locale'] ?? 'es');

        $lock = $sessionManager->acquireProcessingLock($this->sessionId);

        if (! $lock->get()) {
            Log::debug('[ProcessVoiceInterviewTurnJob] Lock no adquirido, turno concurrente ignorado', [
                'session_id' => $this->sessionId,
            ]);
            return;
        }

        try {
            $this->processTurn($state, $turnAgent, $analyst, $interviewer, $sessionManager, $translator);
        } finally {
            $lock->release();
        }

        Log::info('[ProcessVoiceInterviewTurnJob] Fin', [
            'session_id' => $this->sessionId,
        ]);
    }

    private function processTurn(
        array $state,
        VoiceInterviewTurnAgent $turnAgent,
        VoiceAnalystAgent $analyst,
        InterviewerAgent $interviewer,
        VoiceInterviewSessionManager $sessionManager,
        VoiceTextTranslator $translator,
    ): void {
        $archetypeId = $state['current_archetype_id'] ?? null;
        $player      = Player::where('discord_id', $this->discordId)->first();
        $playerId    = $player?->id;
        $locale      = $state['locale'] ?? 'es';

        // All field types — voice asks every field verbally, not just text
        $allFields = $interviewer->resolveFields($archetypeId, 'registration');

        $requiredFieldKeys = array_column(array_filter($allFields, fn($f) => $f['is_required']), 'field_key');
        $optionalFieldKeys = array_column(array_filter($allFields, fn($f) => ! $f['is_required']), 'field_key');

        $conversationHistory = $state['conversation_history'] ?? [];

        try {
            // ── 1. Unified agent: extracts fields + generates next question in ONE LLM call ──
            $turnResult = $turnAgent->processTurn(
                $this->transcript,
                $allFields,
                $state['extracted_fields'] ?? [],
                $conversationHistory,
                $playerId,
            );

            $responseType = $turnResult['response_type'];

            Log::debug('[ProcessVoiceInterviewTurnJob] Turn agent result', [
                'response_type'   => $responseType,
                'extracted'       => array_keys($turnResult['extracted'] ?? []),
                'has_next'        => $turnResult['next_question'] !== null,
            ]);

            if ($responseType !== 'answer') {
                $redirect = trans('discord.voice_off_topic_redirect', [], 'en');
                $sessionManager->pushNextQuestion($this->sessionId, $redirect);

                Log::info('[ProcessVoiceInterviewTurnJob] Respuesta off-topic — redireccionando', [
                    'response_type' => $responseType,
                ]);
                return;
            }

            // ── 2. Merge extracted fields ────────────────────────────────────
            $newExtracted = array_merge(
                $state['extracted_fields'] ?? [],
                $turnResult['extracted'] ?? [],
            );

            // ── 3. Analyst: authoritative completeness check (PHP — free) ────
            $analystResult = $analyst->analyze($newExtracted, $requiredFieldKeys, $optionalFieldKeys);

            // ── 4. Update conversation history + session state ───────────────
            $newHistory   = $conversationHistory;
            $newHistory[] = ['role' => 'user', 'content' => $this->transcript];

            $updatedState = array_merge($state, [
                'turn'                  => ($state['turn'] ?? 0) + 1,
                'extracted_fields'      => $newExtracted,
                'missing_required_keys' => $analystResult['missing_required'],
                'missing_optional_keys' => $analystResult['missing_optional'],
                'conversation_history'  => $newHistory,
            ]);

            $sessionManager->updateSession($this->sessionId, $updatedState);

            // ── 5. Complete or deliver next question ─────────────────────────
            if ($analystResult['is_complete']) {
                Log::info('[ProcessVoiceInterviewTurnJob] Archetype completo', [
                    'archetype_id' => $archetypeId,
                    'extracted'    => array_keys($newExtracted),
                ]);

                $sessionManager->completeCurrentArchetype(
                    $this->sessionId,
                    $newExtracted,
                    $this->discordId,
                    $state['username'] ?? '',
                );

                $hasNext = $sessionManager->advanceToNextArchetype($this->sessionId);

                if (! $hasNext) {
                    $sessionManager->pushNextQuestion(
                        $this->sessionId,
                        trans('discord.voice_session_complete', [], 'en'),
                    );
                    Log::info('[ProcessVoiceInterviewTurnJob] Sesión de voz completada', [
                        'session_id' => $this->sessionId,
                    ]);
                    return;
                }

                $freshState      = $sessionManager->getSession($this->sessionId);
                $openingQuestion = $sessionManager->resolveOpeningQuestion(
                    $freshState['current_archetype_id'] ?? '',
                    $freshState['username']              ?? '',
                    $locale,
                );

                $sessionManager->pushNextQuestion($this->sessionId, $translator->toEnglish($openingQuestion));

            } else {
                // ── 5b. Deliver next question from unified agent ─────────────
                // LLM already generated it with full context — use it directly.
                // Fall back to a generic prompt if the agent returned null.
                $question = $turnResult['next_question']
                    ?? trans('discord.voice_error_processing', [], 'en');

                $updatedState['conversation_history'][] = ['role' => 'assistant', 'content' => $question];
                $sessionManager->updateSession($this->sessionId, $updatedState);

                // Question already in English — no translation needed
                $sessionManager->pushNextQuestion($this->sessionId, $question);

                Log::info('[ProcessVoiceInterviewTurnJob] Siguiente pregunta enviada', [
                    'question' => mb_substr($question, 0, 80),
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('[ProcessVoiceInterviewTurnJob] Error en pipeline', [
                'session_id' => $this->sessionId,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            $sessionManager->pushNextQuestion(
                $this->sessionId,
                trans('discord.voice_error_processing', [], 'en'),
            );

            throw $e;
        }
    }
}
