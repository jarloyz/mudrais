<?php

namespace App\Jobs\Discord;

use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Community\Models\GameItem;
use App\Domains\Community\Models\Player;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Infrastructure\Ai\Agents\InterviewGatekeeperAgent;
use App\Infrastructure\Ai\Agents\RegistrationAnalystAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta un turno de la entrevista conversacional mediante un pipeline de 3 agentes:
 *
 * Turno 0 (userAnswer vacío): envía la pregunta de apertura sin llamada a IA.
 * Turno 1+:
 *   1. InterviewGatekeeperAgent — traduce + spam check + extracción de campos
 *   2. RegistrationAnalystAgent — determina completitud (pure PHP)
 *   3. InterviewerAgent         — formula la siguiente pregunta (si falta algo)
 *      Si todo está completo → despacha ProcessRegistroStep2Job directamente.
 */
class ProcessInterviewTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public int $timeout = 120;
    public int $tries   = 3; // 3 excepciones reales antes de marcar como fallido

    private const MAX_RATE_LIMIT_RELEASES = 4; // máx intentos por rate limit antes de renunciar

    private const MAX_FREE_TURNS = 2;       // turnos gratuitos; del 3 en adelante se cobra energía por turno
    private const MAX_QUESTION_DEFLECTIONS = 2; // máx aclaraciones sin consumir turno
    private const CACHE_TTL = 30; // minutos

    public function __construct(
        public readonly string  $discordId,
        public readonly ?string $token,
        public readonly string  $userAnswer,
        public readonly int     $turn,
        public readonly string  $username,
        public readonly ?string $guildId  = null,
        public readonly ?string $threadId = null,
    ) {
        $this->onQueue('default');
    }

    /**
     * Envía una respuesta al usuario enrutando según el modo:
     *   - threadId presente: sendMessage() al hilo vía Bot Beta.
     *   - threadId ausente:  sendFollowUp() via webhook (Bot Alpha).
     *
     * En modo hilo no aplica la flag efímera — los mensajes son visibles en el hilo.
     */
    private function replyToUser(string $content, array $extra = []): void
    {
        if ($this->threadId && $this->guildId) {
            $data = array_filter(array_merge(['content' => $content ?: null], $extra));
            \App\Services\Discord\DiscordApiService::forGuild($this->guildId)
                ->sendMessage($this->threadId, $data);
        } else {
            $this->sendFollowUp((string) $this->token, $content, $extra, true);
        }
    }

    public function handle(
        InterviewGatekeeperAgent $gatekeeper,
        RegistrationAnalystAgent $analyst,
        InterviewerAgent $interviewer,
    ): void {
        Log::info('[ProcessInterviewTurnJob] Inicio', [
            'discord_id' => $this->discordId,
            'turn'       => $this->turn,
            'has_answer' => $this->userAnswer !== '',
        ]);

        $state = Cache::get("interview_state_{$this->discordId}");

        if (! $state) {
            Log::warning('[ProcessInterviewTurnJob] Sesión expirada', ['discord_id' => $this->discordId]);
            $this->replyToUser(__('discord.interview_expired'));
            return;
        }

        App::setLocale($state['locale'] ?? 'es');

        // Guard: descarta turnos stale (botón de una sesión anterior)
        if ($this->turn !== $state['turn'] && $this->turn !== 0) {
            Log::debug('[ProcessInterviewTurnJob] Turno stale descartado', [
                'expected' => $state['turn'],
                'received' => $this->turn,
            ]);
            return;
        }

        // ── Turno 0: pregunta de apertura ─────────────────────────────────────
        if ($this->userAnswer === '') {
            $this->sendOpeningTurn($state);
            return;
        }

        // ── Turnos 1+: pipeline de 4 agentes ─────────────────────────────────
        $archetypeId      = $state['archetype_id']       ?? null;
        $interviewContext = $state['interview_context'] ?? 'registration';
        $archetype        = $archetypeId ? Archetype::find($archetypeId) : null;
        $fields           = $interviewer->resolveFields($archetypeId, $interviewContext);

        // Filtrar solo campos de tipo texto para el pipeline de IA
        $storedAiKeys = $state['ai_field_keys'] ?? null;
        $aiFields = $storedAiKeys !== null
            ? array_values(array_filter($fields, fn($f) => in_array($f['field_key'], $storedAiKeys, true)))
            : array_values(array_filter($fields, fn($f) => in_array($f['field_type'] ?? 'text', \App\Infrastructure\Ai\Agents\InterviewerAgent::AI_FIELD_TYPES, true)));

        $player   = Player::where('discord_id', $this->discordId)->first();
        $playerId = $player?->id;
        $guildId  = $state['guild_id'] ?? null;

        try {
            // 1. Gatekeeper: traduce + spam check + extracción (solo campos AI)
            $gatekeeperResult = $gatekeeper->process(
                $this->userAnswer,
                $aiFields,
                $state['extracted_fields'],
                $playerId,
                $archetype,
            );

            $responseType = $gatekeeperResult['response_type'];

            // Penalización por spam o intento de manipulación (non-fatal)
            if (($gatekeeperResult['is_spam'] || $responseType === 'manipulation') && $player && $guildId) {
                $this->applySpamPenalty($player, $guildId);
            }

            // Manejar respuestas no-informativas antes de continuar el pipeline
            if ($responseType !== 'answer') {
                $this->handleNonAnswer(
                    $responseType,
                    $state,
                    $aiFields,
                    $gatekeeperResult['question_field'] ?? null,
                    $gatekeeperResult['explanation'] ?? null,
                );
                return;
            }

            // 2. Merge: campos previos + extraídos por Gatekeeper
            $newExtracted = array_merge(
                $state['extracted_fields'],
                $gatekeeperResult['extracted'],
            );

            // 3. Analyst: determina completitud de campos AI (pure PHP, no puede fallar por rate limit)
            $requiredFieldKeys = $state['required_field_keys'] ?? array_column(
                array_filter($aiFields, fn($f) => $f['is_required']),
                'field_key'
            );
            $optionalFieldKeys = $state['optional_field_keys'] ?? array_column(
                array_filter($aiFields, fn($f) => ! $f['is_required']),
                'field_key'
            );
            $analystResult = $analyst->analyze($newExtracted, $requiredFieldKeys, $optionalFieldKeys);

            // Actualizar historial con la respuesta del usuario
            $newHistory   = $state['conversation_history'] ?? [];
            $newHistory[]  = ['role' => 'user', 'content' => $this->userAnswer];

            $newTurn = $state['turn'] + 1;

            $updatedState = array_merge($state, [
                'turn'                  => $newTurn,
                'extracted_fields'      => $newExtracted,
                'missing_required_keys' => $analystResult['missing_required'],
                'missing_optional_keys' => $analystResult['missing_optional'],
                'conversation_history'  => $newHistory,
            ]);

            if ($analystResult['is_complete']) {
                // ── Todos los campos AI completos ─────────────────────────────────
                $formFieldKeys = $updatedState['form_field_keys'] ?? [];

                if (! empty($formFieldKeys)) {
                    // Quedan campos de formulario (select, number, etc.) → modal primero
                    $updatedState['status'] = 'awaiting_form';
                    Cache::put("interview_state_{$this->discordId}", $updatedState, now()->addMinutes(self::CACHE_TTL));
                    $this->sendFormBridgeEmbed();
                } else {
                    // Sin campos de formulario → registro directo
                    $updatedState['status'] = 'completed';
                    Cache::put("interview_state_{$this->discordId}", $updatedState, now()->addMinutes(self::CACHE_TTL));
                    $this->dispatchRegistrationDirect($updatedState, $archetypeId, $player);
                }

            } else {
                // ── Seguir preguntando: cobrar energía si ya pasó el umbral gratuito ──
                if ($newTurn > self::MAX_FREE_TURNS && $player && $this->guildId) {
                    $cancelled = $this->chargeInterviewTurnEnergy($player, $this->guildId, $newTurn, $updatedState);
                    if ($cancelled) {
                        return;
                    }
                }

                $allMissingKeys = array_merge(
                    $analystResult['missing_required'],
                    $analystResult['missing_optional'],
                );
                $question = $interviewer->formulateQuestion(
                    $allMissingKeys,
                    $aiFields,
                    $newHistory,
                    $archetypeId,
                    $playerId,
                );

                $updatedState['conversation_history'][] = ['role' => 'assistant', 'content' => $question];

                Cache::put("interview_state_{$this->discordId}", $updatedState, now()->addMinutes(self::CACHE_TTL));
                $this->sendNextQuestion($question, $newTurn);
            }

        } catch (\Throwable $e) {
            if ($this->isRateLimitException($e)) {
                if ($this->attempts() >= self::MAX_RATE_LIMIT_RELEASES) {
                    Log::error('[ProcessInterviewTurnJob] Rate limit agotado — notificando usuario', [
                        'discord_id' => $this->discordId,
                        'attempts'   => $this->attempts(),
                    ]);
                    $this->replyToUser(__('discord.interview_rate_limit_fatal'));
                    return; // job completa sin excepción — no se marca como failed
                }

                $delay = $this->resolveRetryDelay($e);
                Log::warning('[ProcessInterviewTurnJob] Rate limit — reencolando', [
                    'discord_id' => $this->discordId,
                    'turn'       => $this->turn,
                    'attempt'    => $this->attempts(),
                    'delay_s'    => $delay,
                ]);
                $this->release($delay);
                return;
            }

            Log::error('[ProcessInterviewTurnJob] Error no recuperable', [
                'discord_id' => $this->discordId,
                'turn'       => $this->turn,
                'error'      => $e->getMessage(),
            ]);
            $this->replyToUser(__('discord.interview_error_retry'));
            throw $e;
        }

        Log::info('[ProcessInterviewTurnJob] Fin', [
            'discord_id' => $this->discordId,
            'turn'       => $newTurn ?? $this->turn,
            'complete'   => $analystResult['is_complete'] ?? false,
        ]);
    }

    private function dispatchRegistrationDirect(array $state, ?string $archetypeId, ?Player $player): void
    {
        $isEdit = $state['is_edit'] ?? false;

        Cache::put("registro_step1_{$this->discordId}", [
            'is_edit'     => $isEdit,
            'nationality' => $player?->nationality,
        ], now()->addMinutes(30));

        if ($archetypeId) {
            Cache::put("registro_archetype_{$this->discordId}", $archetypeId, now()->addMinutes(30));
        }

        Log::info('[ProcessInterviewTurnJob] Campos completos — despachando registro directo', [
            'discord_id'   => $this->discordId,
            'archetype_id' => $archetypeId,
            'is_edit'      => $isEdit,
            'fields'       => array_keys($state['extracted_fields']),
        ]);

        $this->replyToUser(__('discord.interview_processing_registration'));

        ProcessRegistroStep2Job::dispatch(
            discordId: $this->discordId,
            data:      $state['extracted_fields'],
            token:     $this->token ?? '',
            guildId:   $this->guildId,
            username:  $this->username,
            threadId:  $this->threadId,
        );
    }

    /**
     * Cobra el costo de energía por turno extra de entrevista.
     * Devuelve true si la sesión debe cancelarse (saldo insuficiente).
     */
    private function chargeInterviewTurnEnergy(Player $player, string $guildId, int $turn, array $updatedState): bool
    {
        try {
            $effect = GameItem::resolveForGuild('interview_turn', $guildId);
            $cost   = abs((int) ($effect['energy_delta'] ?? 0));

            if ($cost === 0) {
                return false;
            }

            $player->deductEnergy($cost, 'interview_turn', [
                'discord_id' => $this->discordId,
                'turn'       => $turn,
            ]);

            Log::info('[ProcessInterviewTurnJob] Turno de entrevista cobrado', [
                'discord_id'   => $this->discordId,
                'turn'         => $turn,
                'energy_cost'  => $cost,
            ]);

            return false;

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // GameItem 'interview_turn' no configurado para esta guild — turno gratuito
            Log::debug('[ProcessInterviewTurnJob] GameItem interview_turn no configurado — turno sin costo', [
                'discord_id' => $this->discordId,
                'guild_id'   => $guildId,
            ]);
            return false;

        } catch (\RuntimeException) {
            // Energía insuficiente → cancelar sesión
            $updatedState['status'] = 'cancelled';
            Cache::put("interview_state_{$this->discordId}", $updatedState, now()->addMinutes(self::CACHE_TTL));

            Log::warning('[ProcessInterviewTurnJob] Energía insuficiente — sesión de entrevista cancelada', [
                'discord_id' => $this->discordId,
                'turn'       => $turn,
            ]);

            $this->replyToUser(__('discord.interview_energy_depleted'));
            return true;

        } catch (\Throwable $e) {
            // Error inesperado en el cobro — no bloquear la entrevista
            Log::warning('[ProcessInterviewTurnJob] Error al cobrar turno de entrevista (non-fatal)', [
                'discord_id' => $this->discordId,
                'turn'       => $turn,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function sendOpeningTurn(array $state): void
    {
        $archetypeId      = $state['archetype_id']       ?? null;
        $interviewContext = $state['interview_context'] ?? 'registration';

        // Elegir la clave de prompt según el contexto de la entrevista
        $agentPromptKey = match ($interviewContext) {
            'avatar_context'  => 'interview_opening_avatar',
            'activities_vibe' => 'interview_opening_activity',
            default           => 'interview_opening',
        };
        $i18nFallbackKey = match ($interviewContext) {
            'avatar_context'  => 'interview_opening_avatar',
            'activities_vibe' => 'interview_opening_activity',
            default           => 'interview_opening_question',
        };

        // Pregunta de apertura: del arquetipo → AiPromptTemplate → i18n fallback
        $openingQuestion = null;

        if ($archetypeId) {
            $archetype       = Archetype::find($archetypeId);
            $openingQuestion = $archetype?->getPromptFor($agentPromptKey);
        }

        if (! $openingQuestion) {
            $globalOpening = \App\Models\AiPromptTemplate::getBody($agentPromptKey, '');
            $openingQuestion = $globalOpening !== ''
                ? str_replace('{username}', $this->username, $globalOpening)
                : __("discord.{$i18nFallbackKey}", ['username' => $this->username]);
        }

        $history   = $state['conversation_history'] ?? [];
        $history[] = ['role' => 'assistant', 'content' => $openingQuestion];

        $updatedState = array_merge($state, [
            'turn'                 => 1,
            'conversation_history' => $history,
        ]);

        Cache::put("interview_state_{$this->discordId}", $updatedState, now()->addMinutes(self::CACHE_TTL));

        // En modo hilo el usuario escribe libremente — no hace falta la instrucción de slash command
        $text = $this->threadId
            ? $openingQuestion
            : "{$openingQuestion}\n\n" . __('discord.interview_respond_instruction');

        $this->replyToUser($text);

        Log::info('[ProcessInterviewTurnJob] Pregunta de apertura enviada', ['discord_id' => $this->discordId]);
    }

    private function sendNextQuestion(string $question, int $turn): void
    {
        $turnLabel = __('discord.interview_turn_label', ['turn' => $turn]);

        // En modo hilo el usuario escribe libremente — sin instrucción de slash command
        $text = $this->threadId
            ? "_{$turnLabel}_\n\n{$question}"
            : "_{$turnLabel}_\n\n{$question}\n\n" . __('discord.interview_respond_instruction');

        $this->replyToUser($text);
    }

    /**
     * Maneja respuestas que no aportan información: preguntas del usuario, off-topic y manipulaciones.
     *
     * - question (bajo el límite): usa la explicación generada por el Gatekeeper en el idioma del usuario.
     *   Fallback a i18n si el Gatekeeper no generó explicación. No consume turno.
     * - off_topic / manipulation / question (límite superado): redirige y consume el turno.
     *
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $aiFields
     */
    private function handleNonAnswer(
        string $responseType,
        array $state,
        array $aiFields,
        ?string $questionField = null,
        ?string $gatekeeperExplanation = null,
    ): void {
        Log::info('[ProcessInterviewTurnJob] Respuesta no-informativa detectada', [
            'discord_id'      => $this->discordId,
            'response_type'   => $responseType,
            'question_field'  => $questionField,
            'has_explanation' => $gatekeeperExplanation !== null,
            'turn'            => $state['turn'],
        ]);

        $instruction = $this->threadId ? '' : ("\n\n" . __('discord.interview_respond_instruction'));

        if ($responseType === 'question') {
            $questionCount = ($state['question_count'] ?? 0) + 1;

            if ($questionCount <= self::MAX_QUESTION_DEFLECTIONS) {
                $updatedState = array_merge($state, ['question_count' => $questionCount]);
                Cache::put("interview_state_{$this->discordId}", $updatedState, now()->addMinutes(self::CACHE_TTL));

                // Preferir la explicación generada por el Gatekeeper (ya en el idioma del usuario)
                if ($gatekeeperExplanation !== null) {
                    $explain = $gatekeeperExplanation;
                } elseif ($questionField !== null) {
                    $targetField = array_values(array_filter($aiFields, fn($f) => $f['field_key'] === $questionField))[0] ?? null;
                    $hint        = $targetField['hint'] ?? '';
                    $label       = $targetField['field_label'] ?? $questionField;
                    $explain     = $hint !== ''
                        ? __('discord.interview_question_explain', ['label' => $label, 'hint' => $hint])
                        : __('discord.interview_question_redirect', ['label' => $label]);
                } else {
                    $explain = __('discord.interview_question_generic');
                }

                $this->replyToUser("{$explain}{$instruction}");
                Log::debug('[ProcessInterviewTurnJob] Respuesta a pregunta enviada sin consumir turno', [
                    'discord_id'     => $this->discordId,
                    'question_count' => $questionCount,
                    'question_field' => $questionField,
                    'source'         => $gatekeeperExplanation !== null ? 'gatekeeper' : 'i18n',
                ]);
                return;
            }
        }

        // Para off_topic, manipulation, o question con límite superado: consumir el turno
        $newTurn      = $state['turn'] + 1;
        $newHistory   = $state['conversation_history'] ?? [];
        $newHistory[] = ['role' => 'user', 'content' => $this->userAnswer];

        $updatedState = array_merge($state, [
            'turn'                 => $newTurn,
            'conversation_history' => $newHistory,
        ]);
        Cache::put("interview_state_{$this->discordId}", $updatedState, now()->addMinutes(self::CACHE_TTL));

        $message = match ($responseType) {
            'manipulation' => __('discord.interview_manipulation_redirect'),
            'off_topic'    => __('discord.interview_off_topic_redirect'),
            default        => __('discord.interview_off_topic_redirect'),
        };

        $this->replyToUser("{$message}{$instruction}");
    }

    private function sendFormBridgeEmbed(): void
    {
        $payload = [
            'embeds' => [[
                'title'       => __('discord.interview_form_bridge_title'),
                'description' => __('discord.interview_form_bridge_desc'),
                'color'       => 3447003, // azul #3498DB
                'footer'      => ['text' => __('discord.footer')],
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 1,
                    'label'     => __('discord.interview_form_bridge_btn'),
                    'custom_id' => 'btn_interview_form',
                ]],
            ]],
        ];

        $this->replyToUser('', $payload);

        Log::info('[ProcessInterviewTurnJob] Bridge de campos estructurados enviado', ['discord_id' => $this->discordId]);
    }

    /**
     * @param array<array{field_key:string,field_label:string,is_required:bool,hint:string}> $fields
     */
    private function sendConfirmationEmbed(InterviewerAgent $interviewer, array $state, array $fields): void
    {
        $embFields = $interviewer->buildEmbedFields($state['extracted_fields'], $fields);

        $payload = [
            'embeds'     => [[
                'title'       => __('discord.interview_summary_title'),
                'description' => __('discord.interview_summary_desc'),
                'color'       => 5763719, // verde #57F287
                'fields'      => $embFields,
                'footer'      => ['text' => __('discord.footer')],
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [
                    [
                        'type'      => 2,
                        'style'     => 3,
                        'label'     => __('discord.interview_confirm_btn'),
                        'custom_id' => 'btn_interview_accept',
                    ],
                    [
                        'type'      => 2,
                        'style'     => 2,
                        'label'     => __('discord.interview_retry_btn'),
                        'custom_id' => 'btn_interview_retry',
                    ],
                    [
                        'type'      => 2,
                        'style'     => 4,
                        'label'     => __('discord.interview_cancel_btn'),
                        'custom_id' => 'btn_interview_cancel',
                    ],
                ],
            ]],
        ];

        $this->replyToUser('', $payload);

        Log::info('[ProcessInterviewTurnJob] Embed de confirmación enviado', ['discord_id' => $this->discordId]);
    }

    /**
     * Laravel llama este método cuando el job agota todos los tries.
     * Garantiza que el usuario siempre reciba un mensaje aunque el token esté próximo a expirar.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessInterviewTurnJob] Job fallido definitivamente', [
            'discord_id' => $this->discordId,
            'turn'       => $this->turn,
            'attempts'   => $this->attempts(),
            'error'      => $exception->getMessage(),
        ]);

        try {
            $this->replyToUser(__('discord.interview_error_fatal'));
        } catch (\Throwable $e) {
            // Token de Discord expirado (>15 min) o hilo cerrado — no se puede notificar
            Log::warning('[ProcessInterviewTurnJob] No se pudo notificar al usuario', [
                'discord_id' => $this->discordId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function isRateLimitException(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), '429')
            || str_contains($e->getMessage(), 'rate limit')
            || str_contains($e->getMessage(), 'rate-limit')
            || str_contains($e->getMessage(), 'Rate limit');
    }

    /**
     * Extrae el delay de Retry-After si está en el mensaje, o usa backoff exponencial.
     */
    private function resolveRetryDelay(\Throwable $e): int
    {
        if (preg_match('/retry.?after["\s:]+(\d+)/i', $e->getMessage(), $m)) {
            return max(10, (int) $m[1]);
        }

        // Backoff exponencial: 30s, 60s, 120s según número de intentos liberados
        $attempts = $this->attempts();
        return min(30 * (2 ** max(0, $attempts - 1)), 300);
    }

    private function applySpamPenalty(Player $player, string $guildId): void
    {
        try {
            $effect = GameItem::resolveForGuild('interview_spam', $guildId);
            $cost   = abs((int) ($effect['coin_delta'] ?? 0));

            if ($cost > 0) {
                $player->deductCoins($cost, 'interview_spam', [
                    'discord_id' => $this->discordId,
                    'turn'       => $this->turn,
                ]);

                Log::warning('[ProcessInterviewTurnJob] Penalización spam aplicada', [
                    'discord_id' => $this->discordId,
                    'cost'       => $cost,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[ProcessInterviewTurnJob] Error al aplicar penalización spam (non-fatal)', [
                'discord_id' => $this->discordId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
