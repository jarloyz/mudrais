<?php

namespace App\Domains\Community\Delivery;

use App\Domains\Community\Actions\ValidateGuildAccessAction;
use App\Domains\Community\Models\GameItem;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Actions\ResolveGuildArchetypeAction;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Discord\Embeds\RegistroEmbeds;
use App\Infrastructure\Discord\Modals\RegistroModals;
use App\Jobs\Discord\ProcessBuscarJob;
use App\Jobs\Discord\ProcessFichaModalJob;
use App\Jobs\Discord\ProcessRegistroStep1Job;
use App\Jobs\Discord\ProcessRegistroStep2Job;
use App\Jobs\Discord\ProcessStatusJob;
use App\Models\PlayerArchetypeProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
/**
 * Maneja todas las interacciones que Discord envía al endpoint /api/discord/interactions.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * REGLA DE TIMING — leer antes de modificar este controlador
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Discord exige una respuesta HTTP dentro de los primeros 3 segundos.
 * Todo procesamiento real (IA, vectorización, HTTP externo) ocurre en Jobs
 * de cola DESPUÉS de que la respuesta ya fue enviada a Discord.
 *
 * Tipos de respuesta inmediata:
 *   type:1  — PING ACK
 *   type:4  — CHANNEL_MESSAGE_WITH_SOURCE (flag 64 = efímero)
 *   type:5  — DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE ("pensando...")
 *   type:6  — DEFERRED_UPDATE_MESSAGE
 *   type:7  — UPDATE_MESSAGE (muta el mensaje original)
 *   type:9  — MODAL (⚠ debe ser respuesta directa, no follow-up)
 *
 * ══════════════════════════════════════════════════════════════════════════════
 */
class DiscordWebhookController extends Controller
{


    private const REGISTRO_ITEM_KEY = 'registro_edit';

    public function __construct(
        private readonly ValidateGuildAccessAction  $guildValidator,
        private readonly ResolveGuildArchetypeAction $archetypeResolver,
        private readonly ArchetypeMutatorService    $mutatorService,
    ) {
    }

    // =========================================================================
    // Entry point
    // =========================================================================

    public function handle(Request $request): JsonResponse
    {
        $raw         = $request->getContent();
        $interaction = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('[DiscordWebhookController@handle] Payload JSON inválido', [
                'json_error' => json_last_error_msg(),
                'raw_length' => strlen($raw),
            ]);
            return response()->json(['error' => 'Payload inválido'], 400);
        }

        $type    = (int) ($interaction['type'] ?? -1);
        $token   = $interaction['token'] ?? '';
        $command = $interaction['data']['name'] ?? null;

        Log::debug('[DiscordWebhookController@handle] Interacción recibida', [
            'type'          => $type,
            'command'       => $command,
            'guild_id'      => $interaction['guild_id'] ?? null,
            'token_present' => ! empty($token),
        ]);

        // B2B Guild Validation — PING (type=1) no requiere guild.
        if ($type !== 1 && isset($interaction['guild_id'])) {
            $discordUserId = $this->extractDiscordId($interaction) ?? '';
            $access = $this->guildValidator->execute($interaction['guild_id'], $discordUserId);

            if (! $access->hasAccess) {
                Log::warning('[DiscordWebhookController@handle] Acceso denegado.', [
                    'discord_guild_id' => $interaction['guild_id'],
                    'reason'           => $access->reason,
                ]);
                return response()->json([
                    'type' => 4,
                    'data' => ['content' => '⛔ Este servidor no está activo en MUDRAIS.', 'flags' => 64],
                ]);
            }
        }

        try {
            return match (true) {
                $type === 1
                    => $this->handlePing(),

                $type === 3
                    => $this->handleMessageComponent($interaction, $token),

                $type === 2 && $command === 'registro'
                    => $this->handleRegistroCommand($interaction, $token),

                $type === 2 && $command === 'ficha'
                    => $this->handleFichaCommand(),

                $type === 2
                    => $this->handleSlashCommand($interaction, $token),

                $type === 5
                    => $this->handleModalSubmit($interaction, $token),

                default
                    => $this->handleUnknownType($type),
            };
        } catch (\Throwable $e) {
            Log::error('[DiscordWebhookController@handle] Excepción no controlada', [
                'type'    => $type,
                'command' => $command,
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Error interno'], 500);
        }
    }

    // =========================================================================
    // PING (type 1)
    // =========================================================================

    private function handlePing(): JsonResponse
    {
        Log::debug('[DiscordWebhookController@handlePing] Handshake respondido.');
        return response()->json(['type' => 1]);
    }

    // =========================================================================
    // Slash commands (type 2)
    // =========================================================================

    /**
     * /registro — Máquina de estados.
     *
     * Estados:
     *   NUEVO     → embed verde + botón btn_abrir_modal_1_nuevo
     *   EXISTENTE → validar tutorial y monedas → embed azul + botón btn_abrir_modal_1_edicion
     */
    private function handleRegistroCommand(array $interaction, string $token): JsonResponse
    {
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? '';

        Log::info('[DiscordWebhookController@handleRegistroCommand] Evaluando estado del jugador', [
            'discord_id' => $discordId,
            'guild_id'   => $guildId,
        ]);

        $player = $discordId ? Player::where('discord_id', $discordId)->first() : null;

        if (! $player) {
            Log::debug('[DiscordWebhookController@handleRegistroCommand] Jugador nuevo detectado', [
                'discord_id' => $discordId,
            ]);
            return response()->json(RegistroEmbeds::introNuevo());
        }

        Log::debug('[DiscordWebhookController@handleRegistroCommand] Jugador existente', [
            'player_id'          => $player->id,
            'tutorial_completed' => $player->tutorial_completed,
            'coin'               => $player->coin,
        ]);

        // Verificar si necesita completar perfil de arquetipo (skip tutorial check)
        $archetype = null;
        try {
            $archetype = $this->archetypeResolver->execute($guildId);
        } catch (\Throwable) {
        }

        if ($archetype) {
            $hasProfile = PlayerArchetypeProfile::where('player_id', $player->id)
                ->where('archetype_id', $archetype->id)
                ->exists();

            if (!$hasProfile) {
                Log::info('[DiscordWebhookController@handleRegistroCommand] Sin perfil de arquetipo — introCompletarArquetipo', [
                    'player_id'    => $player->id,
                    'archetype_id' => $archetype->id,
                ]);
                // Cachear arquetipo para que handleAbrirModal2 lo use
                if ($discordId) {
                    Cache::put("registro_archetype_{$discordId}", $archetype->id, now()->addMinutes(30));
                }
                return response()->json(RegistroEmbeds::introCompletarArquetipo());
            }
        }

        if (! $player->tutorial_completed) {
            Log::info('[DiscordWebhookController@handleRegistroCommand] Bloqueado: tutorial pendiente', [
                'player_id' => $player->id,
            ]);
            return $this->ephemeralError('⚠️ Debes completar el **Vault Tutorial** antes de editar tu ficha.');
        }

        try {
            $effect = GameItem::resolveForGuild(self::REGISTRO_ITEM_KEY, $guildId);
        } catch (\Throwable $e) {
            Log::warning('[DiscordWebhookController@handleRegistroCommand] Error al resolver ítem', [
                'player_id' => $player->id,
                'error'     => $e->getMessage(),
            ]);
            return $this->ephemeralError('⚠️ No se pudo determinar el costo de edición. Inténtalo más tarde.');
        }

        $cost = abs($effect['coin_delta']);

        if ($player->coin < $cost) {
            Log::info('[DiscordWebhookController@handleRegistroCommand] Bloqueado: saldo insuficiente', [
                'player_id' => $player->id,
                'coin'      => $player->coin,
                'cost'      => $cost,
            ]);
            return $this->ephemeralError(
                "💸 Editar tu ficha cuesta **{$cost} monedas**. Tu saldo actual es **{$player->coin}**."
            );
        }

        Log::info('[DiscordWebhookController@handleRegistroCommand] Jugador habilitado para editar', [
            'player_id' => $player->id,
            'cost'      => $cost,
        ]);

        return response()->json(RegistroEmbeds::introEdicion($player->coin, $cost));
    }

    /**
     * /ficha — abre el modal de ficha MUDRAIS (modal inmediato, cero I/O).
     */
    private function handleFichaCommand(): JsonResponse
    {
        Log::info('[DiscordWebhookController@handleFichaCommand] Abriendo modal ficha MUDRAIS.');

        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => 'mudrais_ficha',
                'title'      => 'Tu Ficha de Identidad MUDRAIS',
                'components' => [
                    [
                        'type'       => 1,
                        'components' => [[
                            'type'        => 4,
                            'custom_id'   => 'profile_text',
                            'label'       => 'Tu Ficha de Identidad',
                            'style'       => 2,
                            'placeholder' => 'Pega aquí tu ficha rellena...',
                            'min_length'  => 50,
                            'required'    => true,
                        ]],
                    ],
                ],
            ],
        ]);
    }

    // =========================================================================
    // Slash commands (type 2) — deferred (type:5)
    // =========================================================================

    private function handleSlashCommand(array $interaction, string $token): JsonResponse
    {
        $command   = $interaction['data']['name'] ?? '';
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? null;

        Log::info('[DiscordWebhookController@handleSlashCommand] Comando con deferred', [
            'command'    => $command,
            'discord_id' => $discordId,
            'guild_id'   => $guildId,
        ]);

        return match ($command) {
            'status' => $this->deferAndDispatch(
                fn () => ProcessStatusJob::dispatch($discordId, $token)
            ),
            'buscar-partner' => $discordId
                ? $this->deferAndDispatch(
                    fn () => ProcessBuscarJob::dispatch($token, $discordId, $guildId)
                )
                : $this->ephemeralError('No se pudo identificar tu usuario.'),

            default => $this->handleUnknownCommand($command),
        };
    }

    // =========================================================================
    // Modal submits (type 5)
    // =========================================================================

    private function handleModalSubmit(array $interaction, string $token): JsonResponse
    {
        $customIdFull = $interaction['data']['custom_id'] ?? '';
        $parts        = explode(':', $customIdFull);
        $customId     = $parts[0];
        $pageIndex    = (int) ($parts[1] ?? 0);

        $discordId = $this->extractDiscordId($interaction);
        $username  = $this->extractUsername($interaction);
        $guildId   = $interaction['guild_id'] ?? null;

        Log::info('[DiscordWebhookController@handleModalSubmit] Modal submit recibido', [
            'custom_id'  => $customIdFull,
            'page'       => $pageIndex,
            'discord_id' => $discordId,
        ]);

        if (! $discordId) {
            Log::warning('[DiscordWebhookController@handleModalSubmit] discord_id nulo en modal submit', [
                'custom_id' => $customIdFull,
            ]);
            return $this->ephemeralError('No se pudo identificar al jugador.');
        }

        return match ($customId) {
            'mudrais_registro_step_1' => $this->handleRegistroStep1($interaction, $discordId, $username, $guildId, $token),
            'mudrais_registro_step_2' => $this->handleRegistroStep2($interaction, $discordId, $token, $pageIndex),
            'mudrais_ficha'           => $this->handleFichaModal($interaction, $discordId, $token),
            default                   => $this->handleUnknownModal($customId),
        };
    }

    private function handleRegistroStep1(
        array $interaction,
        string $discordId,
        ?string $username,
        ?string $guildId,
        string $token,
    ): JsonResponse {
        $values = $this->extractModalValues($interaction['data']['components'] ?? []);

        Log::debug('[DiscordWebhookController@handleRegistroStep1] Validando campos', [
            'discord_id' => $discordId,
            'edad_raw'   => $values['edad'] ?? null,
        ]);

        $isEdit = (bool) Cache::get("registro_is_edit_{$discordId}", false);

        $edad = filter_var($values['edad'] ?? '', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 13, 'max_range' => 99],
        ]);

        if ($edad === false) {
            Log::warning('[DiscordWebhookController@handleRegistroStep1] Edad inválida', [
                'discord_id' => $discordId,
                'edad_raw'   => $values['edad'] ?? null,
            ]);
            Cache::put("registro_retry_{$discordId}", $values, now()->addMinutes(10));
            return response()->json(['type' => 7, 'data' => RegistroEmbeds::errorStep1Data('La **edad** debe ser un número entre 13 y 99.')]);
        }

        Cache::put("registro_step1_{$discordId}", array_merge($values, [
            'is_edit' => $isEdit,
        ]), now()->addMinutes(30));

        ProcessRegistroStep1Job::dispatch($discordId, $username ?? $discordId, $guildId, $values, $token);

        Log::info('[DiscordWebhookController@handleRegistroStep1] Step1 válido — mutando mensaje a puente Step 2', [
            'discord_id' => $discordId,
            'is_edit'    => $isEdit,
        ]);

        return response()->json(['type' => 7, 'data' => RegistroEmbeds::puenteStep2()]);
    }

    private function handleRegistroStep2(array $interaction, string $discordId, string $token, int $pageIndex = 0): JsonResponse
    {
        $values   = $this->extractModalValues($interaction['data']['components'] ?? []);
        $guildId  = $interaction['guild_id'] ?? null;
        $username = $this->extractUsername($interaction) ?? $discordId;

        // 1. Acumular valores en caché temporal
        $accumulatedKey = "registro_step2_acumulado_{$discordId}";
        $accumulated    = Cache::get($accumulatedKey, []);
        $accumulated    = array_merge($accumulated, $values);
        Cache::put($accumulatedKey, $accumulated, now()->addMinutes(30));

        // 2. Verificar si hay más páginas
        $archetypeId = Cache::get("registro_archetype_{$discordId}");
        $pages       = $this->mutatorService->buildStep2ModalPages($archetypeId, $accumulated);
        $totalPages  = count($pages);

        // Identificar campos presentados hasta la página actual para validar solo esos
        $presentedFields = [];
        for ($i = 0; $i <= $pageIndex; $i++) {
            foreach ($pages[$i] ?? [] as $row) {
                foreach ($row['components'] ?? [] as $comp) {
                    $presentedFields[] = $comp['custom_id'];
                }
            }
        }

        // 3. VALIDACIÓN ESTRICTA (Por cada página y global)
        $missingFields = [];
        if ($archetypeId) {
            $mutators = $this->mutatorService->getFieldsForContext((string)$archetypeId, 'registration');
            foreach ($mutators as $m) {
                if (in_array($m->field_key, $presentedFields)) {
                    $val = $accumulated[$m->field_key] ?? '';
                    if ($m->is_required && trim((string)$val) === '') {
                        $missingFields[] = "**{$m->field_label}**";
                    }
                }
            }
        } else {
            // Fallback legacy
            if (in_array('preferences', $presentedFields) && trim($accumulated['preferences'] ?? '') === '') $missingFields[] = '**Favorites**';
            if (in_array('style', $presentedFields) && trim($accumulated['style'] ?? '') === '') $missingFields[] = '**Style Summary**';
        }

        if (!empty($missingFields)) {
            Log::warning('[DiscordWebhookController@handleRegistroStep2] Campos requeridos vacíos', [
                'discord_id' => $discordId,
                'missing'    => $missingFields,
                'page'       => $pageIndex,
            ]);
            Cache::put("registro_retry_step2_{$discordId}", $accumulated, now()->addMinutes(10));
            $msg = 'Los siguientes campos son obligatorios: ' . implode(', ', $missingFields) . '.';
            return response()->json([
                'type' => 7,
                'data' => RegistroEmbeds::errorStep2Data($msg, $pageIndex)
            ]);
        }

        if (($pageIndex + 1) < $totalPages) {
            Log::info('[DiscordWebhookController@handleRegistroStep2] Transición a siguiente página', [
                'discord_id' => $discordId,
                'next_page'  => $pageIndex + 1,
            ]);
            return response()->json(['type' => 7, 'data' => RegistroEmbeds::puenteStep2Paginado($pageIndex + 1, $totalPages)]);
        }

        $cached = Cache::get("registro_step1_{$discordId}", []);
        $isEdit = (bool) ($cached['is_edit'] ?? false);

        Log::info('[DiscordWebhookController@handleRegistroStep2] Despachando ProcessRegistroStep2Job con datos acumulados', [
            'discord_id' => $discordId,
            'is_edit'    => $isEdit,
        ]);

        ProcessRegistroStep2Job::dispatch($discordId, $accumulated, $token, $guildId, $username);

        // Limpiar acumulado
        Cache::forget($accumulatedKey);

        // type:6 — Deferred Update Message
        return response()->json(['type' => 6]);
    }

    private function handleFichaModal(array $interaction, string $discordId, string $token): JsonResponse
    {
        $profileText = $this->extractModalValue($interaction, 'profile_text');

        Log::info('[DiscordWebhookController@handleFichaModal] Despachando ProcessFichaModalJob', [
            'discord_id'  => $discordId,
            'text_length' => strlen($profileText),
        ]);

        ProcessFichaModalJob::dispatch($discordId, $profileText, $token);

        return response()->json(['type' => 6]);
    }

    // =========================================================================
    // Message components (type 3) — Button Bridge
    // =========================================================================

    private function handleMessageComponent(array $interaction, string $token): JsonResponse
    {
        $customIdFull = $interaction['data']['custom_id'] ?? '';
        $parts        = explode(':', $customIdFull);
        $customId     = $parts[0];
        $pageIndex    = (int) ($parts[1] ?? 0);
        $discordId    = $this->extractDiscordId($interaction);

        Log::info('[DiscordWebhookController@handleMessageComponent] Botón recibido', [
            'custom_id'  => $customIdFull,
            'page'       => $pageIndex,
            'discord_id' => $discordId,
        ]);

        return match ($customId) {
            'btn_reg_hombre',
            'btn_reg_mujer',
            'btn_reg_otro'              => $this->handleSeleccionGenero($interaction, $customId),
            'btn_abrir_modal_1_nuevo'   => $this->handleAbrirModal1Nuevo($interaction),
            'btn_abrir_modal_1_edicion' => $this->handleAbrirModal1Edicion($interaction),
            'btn_retry_modal_1'         => $this->handleRetryModal1($interaction),
            'btn_retry_modal_2'         => $this->handleRetryModal2($interaction),
            'btn_abrir_modal_2',
            'mudrais_abrir_step_2'      => $this->handleAbrirModal2($interaction, $pageIndex),
            default                     => $this->handleUnknownModal($customId),
        };
    }

    private function handleSeleccionGenero(array $interaction, string $customId): JsonResponse
    {
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? '';

        $gender = match ($customId) {
            'btn_reg_hombre' => 'Hombre',
            'btn_reg_mujer'  => 'Mujer',
            default          => 'Otro',
        };

        Log::info('[DiscordWebhookController@handleSeleccionGenero] Género seleccionado', [
            'discord_id' => $discordId,
            'gender'     => $gender,
        ]);

        $archetypeId = $this->resolveAndCacheArchetype($discordId, $guildId);

        if ($discordId) {
            Cache::put("registro_is_edit_{$discordId}", false, now()->addMinutes(30));
            Cache::put("registro_genero_{$discordId}", $gender, now()->addMinutes(30));
        }

        return response()->json(['type' => 9, 'data' => $this->mutatorService->buildStep1Modal($archetypeId, ['genero' => $gender])]);
    }

    private function handleAbrirModal1Nuevo(array $interaction): JsonResponse
    {
        $discordId   = $this->extractDiscordId($interaction);
        $guildId     = $interaction['guild_id'] ?? '';
        $archetypeId = $this->resolveAndCacheArchetype($discordId, $guildId);

        Log::debug('[DiscordWebhookController@handleAbrirModal1Nuevo] Abriendo modal step1 vacío', [
            'discord_id'   => $discordId,
            'archetype_id' => $archetypeId,
        ]);

        if ($discordId) {
            Cache::put("registro_is_edit_{$discordId}", false, now()->addMinutes(30));
        }

        return response()->json(['type' => 9, 'data' => RegistroModals::step1()]);
    }

    private function handleAbrirModal1Edicion(array $interaction): JsonResponse
    {
        $discordId   = $this->extractDiscordId($interaction);
        $guildId     = $interaction['guild_id'] ?? '';
        $archetypeId = $this->resolveAndCacheArchetype($discordId, $guildId);

        Log::debug('[DiscordWebhookController@handleAbrirModal1Edicion] Abriendo modal step1 pre-llenado', [
            'discord_id'   => $discordId,
            'archetype_id' => $archetypeId,
        ]);

        $prefill = [];

        if ($discordId) {
            Cache::put("registro_is_edit_{$discordId}", true, now()->addMinutes(30));

            $player = Player::where('discord_id', $discordId)->first();
            if ($player) {
                $prefill = array_filter([
                    'nombre'       => $player->name,
                    'edad'         => $player->age ? (string) $player->age : null,
                    'nacionalidad' => $player->nationality,
                    'genero'       => $player->gender,
                    'about_me'     => $player->about_me,
                ], fn ($v) => $v !== null);
            }
        }

        return response()->json(['type' => 9, 'data' => RegistroModals::step1(prefill: $prefill)]);
    }

    private function handleRetryModal1(array $interaction): JsonResponse
    {
        $discordId   = $this->extractDiscordId($interaction);
        $guildId     = $interaction['guild_id'] ?? '';
        $archetypeId = $this->resolveAndCacheArchetype($discordId, $guildId);

        Log::debug('[DiscordWebhookController@handleRetryModal1] Abriendo modal step1 con datos de retry', [
            'discord_id'   => $discordId,
            'archetype_id' => $archetypeId,
        ]);

        $prefill = $discordId ? Cache::get("registro_retry_{$discordId}", []) : [];

        return response()->json(['type' => 9, 'data' => RegistroModals::step1(error: true, prefill: $prefill)]);
    }

    private function handleRetryModal2(array $interaction): JsonResponse
    {
        return $this->handleAbrirModal2($interaction);
    }

    /**
     * Resuelve el arquetipo de la guild, lo cachea para el flujo de registro y devuelve su ID.
     */
    private function resolveAndCacheArchetype(?string $discordId, ?string $guildId): ?string
    {
        if (! $guildId) {
            return null;
        }

        try {
            $archetype = $this->archetypeResolver->execute($guildId);
            if ($discordId && $archetype) {
                Cache::put("registro_archetype_{$discordId}", $archetype->id, now()->addMinutes(30));
            }
            return $archetype?->id;
        } catch (\Throwable $e) {
            Log::warning('[DiscordWebhookController@resolveAndCacheArchetype] No se pudo resolver arquetipo', [
                'guild_id' => $guildId,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function handleAbrirModal2(array $interaction, int $pageIndex = 0): JsonResponse
    {
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? '';

        Log::debug('[DiscordWebhookController@handleAbrirModal2] Abriendo modal step2', [
            'discord_id' => $discordId,
            'page'       => $pageIndex,
        ]);

        $prefill = [];
        $archetypeId = $this->resolveAndCacheArchetype($discordId, $guildId);

        if ($discordId) {
            // Prioridad 1: Datos acumulados (navegación entre páginas)
            $accumulated = Cache::get("registro_step2_acumulado_{$discordId}");
            if ($accumulated) {
                return response()->json(['type' => 9, 'data' => $this->mutatorService->buildStep2Modal($archetypeId, prefill: $accumulated, page: $pageIndex)]);
            }

            // Prioridad 2: Caché de reintento (errores de validación)
            $retry = Cache::pull("registro_retry_step2_{$discordId}");
            if ($retry) {
                Log::debug('[DiscordWebhookController@handleAbrirModal2] Usando prefill de reintento', ['discord_id' => $discordId]);
                return response()->json(['type' => 9, 'data' => $this->mutatorService->buildStep2Modal($archetypeId, prefill: $retry, page: $pageIndex)]);
            }

            $cached = Cache::get("registro_step1_{$discordId}", []);
            // Si no hay cache de step1, verificar el flag de is_edit seteado por handleAbrirModal1Edicion
            $isEdit = (bool) ($cached['is_edit'] ?? Cache::get("registro_is_edit_{$discordId}", false));

            // Además, asegurar que el job de step2 reciba is_edit correcto vía cache
            if ($isEdit && empty($cached)) {
                // Construir cache mínimo para que ProcessRegistroStep2Job detecte is_edit
                Cache::put("registro_step1_{$discordId}", ['is_edit' => true], now()->addMinutes(30));
            }

            if ($isEdit) {
                $player = Player::where('discord_id', $discordId)->first();
                if ($player) {
                    $profile = $archetypeId
                        ? PlayerArchetypeProfile::where('player_id', $player->id)
                            ->where('archetype_id', $archetypeId)
                            ->first()
                        : null;

                    $prefill = [
                        'red_lines'          => $player->red_lines
                            ? implode(', ', $player->red_lines)
                            : null,
                        'yellow_lines'       => $player->yellow_lines
                            ? implode(', ', $player->yellow_lines)
                            : null,
                        'preferences'        => $player->affinities
                            ? implode(', ', $player->affinities)
                            : null,
                        'style'              => $player->narrative_style_text,
                        'input_biografia_pg' => $player->about_me,
                        'schedule_raw'       => $profile?->schedule_raw,
                    ];
                }
            }
        }

        return response()->json(['type' => 9, 'data' => $this->mutatorService->buildStep2Modal($archetypeId, prefill: $prefill, page: $pageIndex)]);
    }

    // =========================================================================
    // Error handlers
    // =========================================================================

    private function handleUnknownType(int $type): JsonResponse
    {
        Log::warning('[DiscordWebhookController@handle] Tipo de interacción desconocido', ['type' => $type]);
        return response()->json(['error' => 'Tipo desconocido'], 400);
    }

    private function handleUnknownCommand(string $name): JsonResponse
    {
        Log::warning('[DiscordWebhookController@handleSlashCommand] Comando desconocido', ['command' => $name]);
        return response()->json(['error' => 'Comando desconocido'], 400);
    }

    private function handleUnknownModal(string $customId): JsonResponse
    {
        Log::warning('[DiscordWebhookController@handleModalSubmit] Modal desconocido', ['custom_id' => $customId]);
        return response()->json(['error' => 'Modal desconocido'], 400);
    }

    private function ephemeralError(string $message): JsonResponse
    {
        return response()->json([
            'type' => 4,
            'data' => ['content' => $message, 'flags' => 64],
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function deferAndDispatch(callable $dispatch): JsonResponse
    {
        $dispatch();
        return response()->json(['type' => 5]);
    }

    private function extractDiscordId(array $interaction): ?string
    {
        $user = $interaction['member']['user'] ?? $interaction['user'] ?? null;
        return $user['id'] ?? null;
    }

    private function extractUsername(array $interaction): ?string
    {
        $user = $interaction['member']['user'] ?? $interaction['user'] ?? null;
        return $user['username'] ?? null;
    }

    /** @return array<string, string|null> */
    private function extractModalValues(array $components): array
    {
        $values = [];
        foreach ($components as $row) {
            foreach ($row['components'] ?? [] as $component) {
                $key          = $component['custom_id'] ?? null;
                $values[$key] = $component['values'][0]
                             ?? $component['value']
                             ?? null;
            }
        }
        return $values;
    }

    private function extractModalValue(array $interaction, string $customId): string
    {
        foreach ($interaction['data']['components'] ?? [] as $row) {
            foreach ($row['components'] ?? [] as $component) {
                if (($component['custom_id'] ?? '') === $customId) {
                    return $component['value'] ?? '';
                }
            }
        }
        return '';
    }
}
