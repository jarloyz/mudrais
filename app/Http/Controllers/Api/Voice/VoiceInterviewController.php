<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Infrastructure\Ai\Agents\TalkatorAgent;
use App\Jobs\Voice\ProcessVoiceInterviewTurnJob;
use App\Services\Voice\VoiceInterviewSessionManager;
use App\Services\Voice\VoiceTextTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoiceInterviewController extends Controller
{
    public function __construct(
        private readonly VoiceInterviewSessionManager $sessionManager,
        private readonly TalkatorAgent $talkator,
        private readonly VoiceTextTranslator $translator,
    ) {}

    /**
     * POST /api/voice/session/start
     *
     * Inicializa una sesión de entrevista de voz para un jugador,
     * construye la cola de archetypes incompletos y devuelve la
     * pregunta de apertura del primer archetype.
     */
    public function startSession(Request $request): JsonResponse
    {
        Log::debug('[VoiceInterviewController@startSession] Inicio', [
            'discord_id'       => $request->input('discord_id'),
            'discord_guild_id' => $request->input('discord_guild_id'),
        ]);

        $validated = $request->validate([
            'discord_id'       => ['required', 'string', 'max:30'],
            'discord_guild_id' => ['required', 'string', 'max:30'],
            'locale'           => ['sometimes', 'string', 'in:es,en'],
        ]);

        try {
            $result          = $this->sessionManager->startSession(
                $validated['discord_id'],
                $validated['discord_guild_id'],
                $validated['locale'] ?? 'es',
            );
            $sessionId       = $result['session_id'];
            $openingQuestion = $result['opening_question'];
            $archetypeId     = $result['current_archetype_id'] ?? null;
        } catch (\RuntimeException $e) {
            Log::warning('[VoiceInterviewController@startSession] Error al crear sesión', [
                'message' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 422);
        }

        Log::info('[VoiceInterviewController@startSession] Sesión creada', [
            'session_id' => $sessionId,
        ]);

        // La opening_question_en va al TTS (Speechmatics solo tiene voces en inglés).
        $openingQuestionEn = $this->translator->toEnglish($openingQuestion);

        return response()->json([
            'session_id'          => $sessionId,
            'opening_question'    => $openingQuestion,
            'opening_question_en' => $openingQuestionEn,
            'archetype_id'        => $archetypeId,
        ]);
    }

    /**
     * POST /api/voice/transcription
     *
     * Recibe una transcripción de voz, despacha el job del Interviewer
     * en background y devuelve la respuesta de Talkator en streaming.
     */
    public function handleTranscription(Request $request): StreamedResponse
    {
        Log::debug('[VoiceInterviewController@handleTranscription] Inicio', [
            'session_id' => $request->input('session_id'),
            'discord_id' => $request->input('discord_id'),
            'transcript' => mb_substr((string) $request->input('transcript', ''), 0, 100),
        ]);

        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:100'],
            'transcript' => ['required', 'string', 'min:3', 'max:2000'],
            'discord_id' => ['required', 'string', 'max:30'],
        ]);

        $sessionId  = $validated['session_id'];
        $transcript = $validated['transcript'];
        $discordId  = $validated['discord_id'];

        $session = $this->sessionManager->getSession($sessionId);
        $locale  = $session['locale'] ?? 'es';

        ProcessVoiceInterviewTurnJob::dispatch(
            $sessionId,
            $transcript,
            $discordId,
        );

        $talkator = $this->talkator;

        return new StreamedResponse(
            function () use ($talkator, $transcript, $session): void {
                // Forzar inglés para TTS — Speechmatics solo tiene voces en inglés.
                $talkator->respond(
                    $transcript,
                    'en',
                    function (string $chunk): void {
                        echo $chunk;
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    },
                    $session['current_archetype_id'] ?? null,
                );
            },
            200,
            [
                'Content-Type'      => 'text/plain; charset=utf-8',
                'X-Accel-Buffering' => 'no',
                'Cache-Control'     => 'no-cache',
            ],
        );
    }

    /**
     * GET /api/voice/pending-start
     *
     * El microservicio voice-bridge pollea este endpoint cada 2s para saber
     * si hay una sesión pendiente de iniciar (encolada por DiscordController
     * cuando el usuario usó /voice-interview vía la URL de interactions).
     * LPOP atómico — cada señal se consume una sola vez.
     */
    public function pollPendingStart(): JsonResponse
    {
        $pending = $this->sessionManager->popStartCommand();

        if ($pending === null) {
            return response()->json(['ready' => false]);
        }

        Log::info('[VoiceInterviewController@pollPendingStart] Señal consumida', [
            'discord_id' => $pending['discord_id'],
            'guild_id'   => $pending['guild_id'],
        ]);

        return response()->json([
            'ready'             => true,
            'discord_id'        => $pending['discord_id'],
            'guild_id'          => $pending['guild_id'],
            'locale'            => $pending['locale'] ?? 'es',
            'interaction_token' => $pending['interaction_token'] ?? '',
            'app_id'            => $pending['app_id'] ?? '',
            'created_at'        => $pending['created_at'] ?? 0,
        ]);
    }

    /**
     * GET /api/voice/next-question/{sessionId}
     *
     * Endpoint de polling. El microservicio Node.js llama esto cada 500ms
     * hasta que el Interviewer haya publicado la siguiente pregunta en Redis.
     */
    public function pollNextQuestion(string $sessionId): JsonResponse
    {
        Log::debug('[VoiceInterviewController@pollNextQuestion] Poll', [
            'session_id' => $sessionId,
        ]);

        $question = $this->sessionManager->popNextQuestion($sessionId);

        if ($question === null) {
            return response()->json(['ready' => false]);
        }

        Log::info('[VoiceInterviewController@pollNextQuestion] Pregunta lista', [
            'session_id' => $sessionId,
            'question'   => mb_substr($question, 0, 80),
        ]);

        return response()->json([
            'ready'    => true,
            'question' => $question,
        ]);
    }
}
