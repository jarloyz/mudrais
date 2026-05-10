<?php

namespace App\Http\Controllers\Api;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Http\Controllers\Controller;
use App\Infrastructure\Discord\Embeds\RegistroEmbeds;
use App\Infrastructure\Discord\Modals\RegistroModals;
use App\Jobs\Discord\ProcessBuscarJob;
use App\Jobs\Discord\ProcessCreateContextJob;
use App\Jobs\Discord\ProcessFichaModalJob;
use App\Jobs\Discord\ProcessRegistroStep1Job;
use App\Jobs\Discord\ProcessRegistroStep2Job;
use App\Jobs\Discord\ProcessStatusJob;
use App\Models\GameItem;
use App\Models\Player;
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
 * Si no llega a tiempo, muestra "La aplicación no respondió" al usuario.
 *
 * Por eso, este controlador NUNCA hace I/O costoso (HTTP externo, queries N+1,
 * operaciones lentas) antes de devolver la respuesta.
 *
 * Excepciones permitidas (operaciones O(1) sobre índices primarios):
 *   - Cache::get / Cache::put  — Redis, microsegundos
 *   - Player::where('discord_id', $id)->first() — índice único, ~1ms
 *   - GameItem::resolveForGuild() — 2 queries indexadas, ~2ms
 *
 * Todo procesamiento real (IA, vectorización, HTTP externo) ocurre en Jobs
 * de cola DESPUÉS de que la respuesta ya fue enviada a Discord.
 *
 * Tipos de respuesta inmediata que puede devolver este controlador:
 *   type:1  — PING ACK (handshake, sin datos)
 *   type:4  — CHANNEL_MESSAGE_WITH_SOURCE (mensaje, con flag 64 = efímero)
 *   type:5  — DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE (Discord muestra "pensando...")
 *   type:6  — DEFERRED_UPDATE_MESSAGE (sin indicador visible)
 *   type:9  — MODAL (abre un formulario en el cliente de Discord)
 *             ⚠ Los modales son la ÚNICA respuesta que NO puede ser follow-up.
 *             Deben ser siempre la respuesta directa al interaction.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 */
class DiscordController extends Controller
{
    private const REGISTRO_ITEM_KEY = 'registro_edit';

    public function __construct(
        private \App\Services\Discord\VaultOnboardingService $vaultOnboardingService,
        private \App\Domains\Matchmaking\Services\ArchetypeMutatorService $mutatorService,
        private \App\Application\Services\ArchetypeResolverService $archetypeResolver
    ) {}

    // =========================================================================
    // Entry point
    // =========================================================================

    public function handle(Request $request): JsonResponse
    {
        $raw         = $request->getContent();
        $interaction = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('[DiscordController@handle] Payload JSON inválido', [
                'json_error' => json_last_error_msg(),
                'raw_length' => strlen($raw),
            ]);
            return response()->json(['error' => 'Payload inválido'], 400);
        }

        $type    = (int) ($interaction['type'] ?? -1);
        $token   = $interaction['token'] ?? '';
        $command = $interaction['data']['name'] ?? null;

        Log::debug('[DiscordController@handle] Payload completo recibido', [
            'payload' => $interaction,
        ]);

        Log::debug('[DiscordController@handle] Interacción recibida', [
            'type'          => $type,
            'command'       => $command,
            'guild_id'      => $interaction['guild_id'] ?? null,
            'token_present' => !empty($token),
        ]);

        // ── RESPUESTA A DISCORD ────────────────────────────────────────────────
        // La validación de guild, rol y energía se realiza en middleware antes
        // de llegar aquí (EnsureDiscordGuildRegistered → EnsureDiscordCommandPermission
        // → EnsurePlayerHasEnergy). La guild resuelta está disponible vía
        // $request->attributes->get('guild') si se necesita en los handlers.
        // Todo lo que está debajo de este comentario hasta el return es lo que
        // Discord recibe. Solo I/O O(1) permitido (ver regla arriba).
        // ──────────────────────────────────────────────────────────────────────

        try {
            return match (true) {
                // ── PING: handshake de Discord ─────────────────────────────────
                $type === 1
                    => $this->handlePing(),

                // ── Componentes (botones) — puente entre modales ───────────────
                $type === 3
                    => $this->handleMessageComponent($interaction, $token),

                // ── /registro: gatekeeper de estado ───────────────────────────
                $type === 2 && $command === 'registro'
                    => $this->handleRegistroCommand($interaction, $token),

                // ── /ficha: modal inmediato ────────────────────────────────────
                $type === 2 && $command === 'ficha'
                    => $this->handleFichaCommand(),

                // ── /create_vault: modal inmediato ─────────────────────────────
                $type === 2 && $command === 'create_vault'
                    => $this->handleCreateVaultCommand($interaction),

                // ── /create: detecta canal → vault → arquetipo → lista + botón ──
                $type === 2 && $command === 'create'
                    => $this->handleCreateContextCommand($interaction),

                // ── /actividad: modal inmediato con ctx1 + ctx2 ───────────────
                $type === 2 && $command === 'actividad'
                    => $this->handleActividadCommand($interaction),

                // ── /buscar-actividad: deferred → búsqueda multi-vector ────────
                $type === 2 && $command === 'buscar-actividad'
                    => $this->handleBuscarActividadCommand($interaction, $token),

                // ── Autocomplete ───────────────────────────────────────────────
                $type === 4
                    => $this->handleAutocomplete($interaction),

                // ── Slash commands con deferred (type:5) ───────────────────────
                $type === 2
                    => $this->handleSlashCommand($interaction, $token),

                // ── Modal submits ──────────────────────────────────────────────
                $type === 5
                    => $this->handleModalSubmit($interaction, $token),

                default
                    => $this->handleUnknownType($type),
            };
        } catch (\Throwable $e) {
            Log::error('[DiscordController@handle] Excepción no controlada — respuesta 500 enviada a Discord', [
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
        Log::debug('[DiscordController@handlePing] Handshake respondido.');
        return response()->json(['type' => 1]);
    }

    // =========================================================================
    // Slash commands (type 2)
    // =========================================================================

    /**
     * /registro — Máquina de estados.
     *
     * En vez de abrir el modal directamente, evalúa el estado del jugador
     * y devuelve el embed introductorio apropiado + botón para abrir el modal.
     *
     * Estados:
     *   NUEVO     → embed verde + botón btn_abrir_modal_1_nuevo
     *   EXISTENTE → validar tutorial y monedas → embed azul + botón btn_abrir_modal_1_edicion
     */
    private function handleRegistroCommand(array $interaction, string $token): JsonResponse
    {
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? '';
        $channelId = $interaction['channel_id'] ?? '';

        Log::info('[DiscordController@handleRegistroCommand] Evaluando estado del jugador', [
            'discord_id' => $discordId,
            'guild_id'   => $guildId,
            'channel_id' => $channelId,
        ]);

        // Resolver arquetipo del canal si es un Vault, sino usar el de la guild
        $archetypeId = null;
        if ($channelId) {
            $vault = \App\Domains\Narrative\Models\Vault::where('discord_channel_id', $channelId)->first();
            if ($vault) {
                $archetypeId = $vault->primaryArchetype()?->id;
                if ($discordId && $archetypeId) {
                    Cache::put("registro_archetype_{$discordId}", $archetypeId, now()->addMinutes(30));
                }
            }
        }

        if (! $archetypeId && $guildId) {
            $archetypeId = $this->resolveAndCacheArchetype($discordId, $guildId);
        }

        // Query O(1) — índice único en discord_id
        $player = $discordId ? Player::where('discord_id', $discordId)->first() : null;

        // ── Jugador NUEVO ─────────────────────────────────────────────────────
        if (! $player) {
            Log::debug('[DiscordController@handleRegistroCommand] Jugador nuevo detectado', [
                'discord_id' => $discordId,
            ]);
            return response()->json(RegistroEmbeds::introNuevo());
        }

        // ── Jugador EXISTENTE — validaciones ──────────────────────────────────
        Log::debug('[DiscordController@handleRegistroCommand] Jugador existente', [
            'player_id'           => $player->id,
            'tutorial_completed'  => $player->tutorial_completed,
            'coin'                => $player->coin,
        ]);

        // Tercer estado: Player existe pero sin perfil de arquetipo en este vault.
        // Se salta Step 1 (datos básicos ya guardados) y se abre Modal 2 de forma gratuita.
        if ($archetypeId) {
            $hasProfile = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile
                ::where('player_id', $player->id)
                ->where('archetype_id', $archetypeId)
                ->exists();

            if (! $hasProfile) {
                Log::info('[DiscordController@handleRegistroCommand] Jugador sin perfil de arquetipo — redirigiendo a Step 2', [
                    'player_id'    => $player->id,
                    'archetype_id' => $archetypeId,
                ]);

                // Pre-cachear is_edit=false para que handleAbrirModal2() no lo promueva a true
                // (la lógica "Bug 4 Fix" en ese método cobra monedas si detecta Player existente
                // y el flag no fue seteado explícitamente).
                Cache::put("registro_step1_{$discordId}", [
                    'is_edit'     => false,
                    'nationality' => $player->nationality,
                ], now()->addMinutes(30));

                return response()->json(RegistroEmbeds::introCompletarArquetipo());
            }
        }

        if (! $player->tutorial_completed) {
            Log::info('[DiscordController@handleRegistroCommand] Bloqueado: tutorial pendiente', [
                'player_id' => $player->id,
            ]);
            return $this->ephemeralError('⚠️ Debes completar el **Vault Tutorial** antes de editar tu ficha.');
        }

        // Resuelve el costo de edición para este servidor (con posibles overrides)
        try {
            $effect = GameItem::resolveForGuild(self::REGISTRO_ITEM_KEY, $guildId);
        } catch (\Throwable $e) {
            Log::warning('[DiscordController@handleRegistroCommand] Error al resolver ítem', [
                'player_id' => $player->id,
                'error'     => $e->getMessage(),
            ]);
            return $this->ephemeralError('⚠️ No se pudo determinar el costo de edición. Inténtalo más tarde.');
        }

        $cost = abs($effect['coin_delta']);

        if ($player->coin < $cost) {
            Log::info('[DiscordController@handleRegistroCommand] Bloqueado: saldo insuficiente', [
                'player_id' => $player->id,
                'coin'      => $player->coin,
                'cost'      => $cost,
            ]);
            return $this->ephemeralError(
                "💸 Editar tu ficha cuesta **{$cost} monedas**. Tu saldo actual es **{$player->coin}**."
            );
        }

        Log::info('[DiscordController@handleRegistroCommand] Jugador habilitado para editar', [
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
        Log::info('[DiscordController@handleFichaCommand] Abriendo modal ficha MUDRAIS.');

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

    /**
     * /create_vault — abre el modal de creación de vault.
     */
    private function handleCreateVaultCommand(array $interaction): JsonResponse
    {
        Log::info('[DiscordController@handleCreateVaultCommand] Abriendo modal creación de vault.');

        $archetypeId = $this->extractOptionValue($interaction, 'archetype');

        $discordId = $this->extractDiscordId($interaction);
        if ($discordId) {
            Cache::forget("vault_onboarding_{$discordId}");
        }

        $pages = $this->mutatorService->buildVaultModalPages($archetypeId);
        $firstPage = $pages[0] ?? [];
        $totalPages = count($pages);
        $titleSuffix = $totalPages > 1 ? ' (Paso 1 de ' . $totalPages . ')' : '';

        $responsePayload = [
            'type' => 9,
            'data' => [
                'custom_id'  => "create_vault_modal:{$archetypeId}:0",
                'title'      => 'Crear Nuevo Vault' . $titleSuffix,
                'components' => $firstPage,
            ],
        ];

        Log::debug('[DiscordController@handleCreateVaultCommand] Payload enviado a Discord', [
            'payload' => $responsePayload,
        ]);

        return response()->json($responsePayload);
    }

    /**
     * APPLICATION_COMMAND_AUTOCOMPLETE (type 4)
     */
    private function handleAutocomplete(array $interaction): JsonResponse
    {
        $command = $interaction['data']['name'] ?? '';
        $options = $interaction['data']['options'] ?? [];
        $focused = collect($options)->firstWhere('focused', true);

        if ($command === 'create_vault' && $focused['name'] === 'archetype') {
            $suggestions = $this->vaultOnboardingService->getArchetypeSuggestions($focused['value'] ?? '');
            return response()->json([
                'type' => 8,
                'data' => ['choices' => $suggestions],
            ]);
        }

        if ($command === 'create' && $focused['name'] === 'type') {
            $channelId   = $interaction['channel_id'] ?? null;
            $suggestions = $this->vaultOnboardingService->getEntityTypeSuggestions(
                $focused['value'] ?? '',
                $channelId
            );
            return response()->json([
                'type' => 8,
                'data' => ['choices' => $suggestions],
            ]);
        }

        if ($command === 'search' && $focused['name'] === 'objetivo') {
            $channelId   = $interaction['channel_id'] ?? null;
            $suggestions = $this->vaultOnboardingService->getSearchTargetSuggestions(
                $focused['value'] ?? '',
                $channelId
            );
            return response()->json([
                'type' => 8,
                'data' => ['choices' => $suggestions],
            ]);
        }

        if ($command === 'buscar-actividad' && ($focused['name'] ?? '') === 'contexto') {
            $channelId = $interaction['channel_id'] ?? null;
            $discordId = $this->extractDiscordId($interaction);

            $suggestions = $this->vaultOnboardingService->getAvatarSuggestions(
                $focused['value'] ?? '',
                $channelId,
                $discordId
            );
            return response()->json(['type' => 8, 'data' => ['choices' => $suggestions]]);
        }

        if ($command === 'actividad') {
            $subOptions = $interaction['data']['options'][0]['options'] ?? [];
            $focused    = collect($subOptions)->firstWhere('focused', true) ?? $focused;
            $channelId  = $interaction['channel_id'] ?? null;
            $discordId  = $this->extractDiscordId($interaction);

            Log::debug('[DiscordController@handleAutocomplete] Autocomplete /actividad', [
                'focused_field' => $focused['name'] ?? null,
                'channel_id'    => $channelId,
            ]);

            $suggestions = $this->vaultOnboardingService->getAvatarSuggestions(
                $focused['value'] ?? '',
                $channelId,
                $discordId
            );
            return response()->json([
                'type' => 8,
                'data' => ['choices' => $suggestions],
            ]);
        }

        return response()->json([
            'type' => 8,
            'data' => ['choices' => []],
        ]);
    }

    private function handleSlashCommand(array $interaction, string $token): JsonResponse
    {
        $command   = $interaction['data']['name'] ?? '';
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? null;

        Log::info('[DiscordController@handleSlashCommand] Comando con deferred', [
            'command'    => $command,
            'discord_id' => $discordId,
            'guild_id'   => $guildId,
        ]);

        return match ($command) {
            'status' => $this->deferAndDispatch(
                fn() => ProcessStatusJob::dispatch($discordId, $token)
            ),
            'buscar-partner' => $discordId
                ? $this->deferAndDispatch(
                    fn() => ProcessBuscarJob::dispatch($token, $discordId, $guildId)
                )
                : $this->ephemeralError('No se pudo identificar tu usuario.'),
            'search' => $discordId
                ? $this->deferAndDispatch(
                    fn() => \App\Jobs\Discord\ProcessSearchJob::dispatch(
                        $token,
                        $discordId,
                        $guildId,
                        $interaction['channel_id'] ?? null,
                        $this->extractOptionValue($interaction, 'objetivo'),
                        $this->extractOptionValue($interaction, 'texto'),
                        $this->extractOptionValue($interaction, 'periodo')
                    )
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
        $customId  = $interaction['data']['custom_id'] ?? '';
        $discordId = $this->extractDiscordId($interaction);
        $username  = $this->extractUsername($interaction);
        $guildId   = $interaction['guild_id'] ?? null;

        Log::info('[DiscordController@handleModalSubmit] Modal submit recibido', [
            'custom_id'  => $customId,
            'discord_id' => $discordId,
        ]);

        if (! $discordId) {
            Log::warning('[DiscordController@handleModalSubmit] discord_id nulo en modal submit', [
                'custom_id' => $customId,
            ]);
            return $this->ephemeralError('No se pudo identificar al jugador.');
        }

        return match (true) {
            $customId === 'mudrais_registro_step_1'                         => $this->handleRegistroStep1($interaction, $discordId, $username, $guildId, $token),
            str_starts_with($customId, 'mudrais_registro_step_2')           => $this->handleRegistroStep2($interaction, $discordId, $token, $customId),
            $customId === 'mudrais_ficha'                                    => $this->handleFichaModal($interaction, $discordId, $token),
            str_starts_with($customId, 'create_vault_modal:')               => $this->handleVaultOnboardingModal($interaction, $customId, $guildId),
            str_starts_with($customId, 'create_context_modal:')             => $this->handleCreateContextModal($interaction, $customId, $discordId, $guildId, $token),
            $customId === 'actividad_modal'                                  => $this->handleActividadModal($interaction, $discordId, $token),
            default                                                          => $this->handleUnknownModal($customId),
        };
    }

    private function handleVaultOnboardingModal(array $interaction, string $customId, ?string $guildId): JsonResponse
    {
        $token = $interaction['token'] ?? '';
        $parts = explode(':', $customId);
        $archetypeId = $parts[1] ?? null;
        $pageIndex = isset($parts[2]) ? (int) $parts[2] : 0;

        $discordId = $this->extractDiscordId($interaction);

        $values = $this->extractModalValues($interaction['data']['components'] ?? []);

        // Guardar valores en cache
        if ($discordId) {
            $cached = Cache::get("vault_onboarding_{$discordId}", []);
            $cached = array_merge($cached, $values);
            Cache::put("vault_onboarding_{$discordId}", $cached, now()->addMinutes(30));
        } else {
            $cached = $values;
        }

        $pages = $this->mutatorService->buildVaultModalPages($archetypeId, $cached);
        $nextPageIndex = $pageIndex + 1;

        if ($nextPageIndex < count($pages)) {
            // Hay más páginas, devolver un mensaje con botón para continuar
            return response()->json([
                'type' => 4, // ephemeral message with button
                'data' => [
                    'content' => '✅ Parte ' . ($pageIndex + 1) . ' completada. Haz clic abajo para continuar.',
                    'flags'   => 64,
                    'components' => [[
                        'type' => 1,
                        'components' => [[
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => 'Continuar (Paso ' . ($nextPageIndex + 1) . ' de ' . count($pages) . ') →',
                            'custom_id' => "vault_continue:{$archetypeId}:{$nextPageIndex}",
                        ]],
                    ]],
                ],
            ]);
        }

        // Si ya no hay más páginas, despachar el job
        $name = $cached['vault_name'] ?? '';
        $description = $cached['vault_description'] ?? '';
        $channelId = $interaction['channel_id'] ?? null;

        // Remove base fields to get only mutators metadata
        $metadata = array_diff_key($cached, array_flip(['vault_name', 'vault_description']));

        Log::info('[DiscordController@handleVaultOnboardingModal] Despachando Job de Onboarding', [
            'guild_id' => $guildId,
            'channel_id' => $channelId,
            'archetype_id' => $archetypeId,
            'name' => $name,
            'metadata_count' => count($metadata),
        ]);

        \App\Jobs\Discord\ProcessVaultOnboardingJob::dispatch(
            $token,
            $guildId,
            $archetypeId,
            $name,
            $description,
            $metadata,
            $channelId
        );

        if ($discordId) {
            Cache::forget("vault_onboarding_{$discordId}");
        }

        return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
    }

    private function handleVaultContinue(array $interaction, string $customId): JsonResponse
    {
        $parts = explode(':', $customId);
        $archetypeId = $parts[1] ?? null;
        $pageIndex = isset($parts[2]) ? (int) $parts[2] : 0;
        $discordId = $this->extractDiscordId($interaction);

        $cached = $discordId ? Cache::get("vault_onboarding_{$discordId}", []) : [];
        $pages = $this->mutatorService->buildVaultModalPages($archetypeId, $cached);
        $pageComponents = $pages[$pageIndex] ?? [];
        $totalPages = count($pages);

        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => "create_vault_modal:{$archetypeId}:{$pageIndex}",
                'title'      => 'Crear Nuevo Vault (Paso ' . ($pageIndex + 1) . ' de ' . $totalPages . ')',
                'components' => $pageComponents,
            ],
        ]);
    }

    /**
     * Step 1 del registro — valida los datos del formulario.
     *
     * Si la validación falla: cachea los valores para retry y devuelve embed de error.
     * Si pasa: cachea todo + flag is_edit, despacha job de persistencia, devuelve botón para step 2.
     */
    private function handleRegistroStep1(
        array   $interaction,
        string  $discordId,
        ?string $username,
        ?string $guildId,
        string  $token,
    ): JsonResponse {
        $values = $this->extractModalValues($interaction['data']['components'] ?? []);

        Log::debug('[DiscordController@handleRegistroStep1] Validando campos', [
            'discord_id' => $discordId,
            'edad_raw'   => $values['edad'] ?? null,
        ]);

        // Recuperar flag is_edit establecido cuando el usuario pulsó el botón
        $isEdit = (bool) Cache::get("registro_is_edit_{$discordId}", false);

        // ── Validación: edad ──────────────────────────────────────────────────
        $edad = filter_var($values['edad'] ?? '', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 13, 'max_range' => 99],
        ]);

        if ($edad === false) {
            Log::warning('[DiscordController@handleRegistroStep1] Edad inválida', [
                'discord_id' => $discordId,
                'edad_raw'   => $values['edad'] ?? null,
            ]);
            Cache::put("registro_retry_{$discordId}", $values, now()->addMinutes(10));
            // type:7 — muta el mensaje original en lugar de crear uno nuevo
            return response()->json(['type' => 7, 'data' => RegistroEmbeds::errorStep1Data('La **edad** debe ser un número entre 13 y 99.')]);
        }

        // ── Validación OK — persiste en background ────────────────────────────
        Cache::put("registro_step1_{$discordId}", array_merge($values, [
            'is_edit' => $isEdit,
        ]), now()->addMinutes(30));

        ProcessRegistroStep1Job::dispatch($discordId, $username ?? $discordId, $guildId, $values, $token);

        Log::info('[DiscordController@handleRegistroStep1] Step1 válido — mutando mensaje a puente Step 2', [
            'discord_id' => $discordId,
            'is_edit'    => $isEdit,
        ]);

        // type:7 — el embed de bienvenida/gender muta al embed puente con botón Continuar
        return response()->json(['type' => 7, 'data' => RegistroEmbeds::puenteStep2()]);
    }

    /**
     * Submit del Modal Step 2 (una página).
     * Si hay más páginas: acumula en caché y devuelve botón "Continuar".
     * Si es la última: despacha ProcessRegistroStep2Job con todos los datos acumulados.
     *
     * custom_id format: mudrais_registro_step_2:{pageIndex}
     */
    private function handleRegistroStep2(array $interaction, string $discordId, string $token, string $customId = 'mudrais_registro_step_2:0'): JsonResponse
    {
        $values      = $this->extractModalValues($interaction['data']['components'] ?? []);
        $guildId     = $interaction['guild_id'] ?? null;
        $username    = $this->extractUsername($interaction) ?? $discordId;

        $parts       = explode(':', $customId);
        $pageIndex   = (int) ($parts[1] ?? 0);
        $idFromCustom = $parts[2] ?? null;

        // Intentar recuperar de custom_id o caché primero para no sobreescribir con el default de la guild
        $archetypeId = $idFromCustom
            ?? ($discordId ? Cache::get("registro_archetype_{$discordId}") : null)
            ?? $this->resolveAndCacheArchetype($discordId, $guildId);

        if (!$archetypeId) {
            Log::warning('[DiscordController@handleRegistroStep2] No se pudo resolver archetypeId, usando fallback', ['discord_id' => $discordId]);
        }

        // Acumular valores de todas las páginas en caché
        $accumulated = Cache::get("registro_step2_{$discordId}", []);
        $accumulated = array_merge($accumulated, $values);
        Cache::put("registro_step2_{$discordId}", $accumulated, now()->addMinutes(30));

        $pages         = $this->mutatorService->buildStep2ModalPages($archetypeId, $accumulated);
        $total         = count($pages);
        $nextPageIndex = $pageIndex + 1;

        Log::info('[DiscordController@handleRegistroStep2] Estado de paginación', [
            'discord_id'   => $discordId,
            'archetype_id' => $archetypeId,
            'page_index'   => $pageIndex,
            'total_pages'  => $total,
            'has_more'     => $nextPageIndex < $total,
            'accumulated'  => count($accumulated),
        ]);

        if ($nextPageIndex < $total) {
            return response()->json([
                'type' => 4,
                'data' => [
                    'content' => "✅ Parte **" . ($pageIndex + 1) . "/{$total}** completada. Continúa para terminar tu ficha.",
                    'flags'   => 64,
                    'components' => [[
                        'type'       => 1,
                        'components' => [[
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => "Continuar (Paso " . ($nextPageIndex + 1) . " de {$total}) →",
                            'custom_id' => "btn_registro_step2_continuar:{$archetypeId}:{$nextPageIndex}",
                        ]],
                    ]],
                ],
            ]);
        }

        // ── VALIDACIÓN FINAL ANTES DE DESPACHAR ──────────────────────────────
        $missingFields = [];
        if ($archetypeId) {
            $mutators = $this->mutatorService->getFieldsForContext($archetypeId, 'registration');
            foreach ($mutators as $m) {
                $val = $accumulated[$m->field_key] ?? '';
                if ($m->is_required && trim((string)$val) === '') {
                    $missingFields[] = "**{$m->field_label}**";
                }
            }
        } else {
            if (trim($accumulated['preferences'] ?? '') === '') $missingFields[] = '**Favorites**';
            if (trim($accumulated['style'] ?? '') === '') $missingFields[] = '**Style Summary**';
        }

        if (!empty($missingFields)) {
            Log::warning('[DiscordController@handleRegistroStep2] Bloqueo por campos vacíos', [
                'discord_id' => $discordId,
                'missing'    => $missingFields,
            ]);
            $msg = 'Los siguientes campos son obligatorios: ' . implode(', ', $missingFields) . '.';
            return response()->json([
                'type' => 4,
                'data' => [
                    'content' => '⚠️ ' . $msg,
                    'flags'   => 64,
                    'components' => [[
                        'type'       => 1,
                        'components' => [[
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => "Reintentar Paso 2 →",
                            'custom_id' => "btn_registro_step2_continuar:{$archetypeId}:0",
                        ]],
                    ]],
                ],
            ]);
        }

        // Última página — despachar con todos los datos acumulados
        ProcessRegistroStep2Job::dispatch($discordId, $accumulated, $token, $guildId, $username);
        Cache::forget("registro_step2_{$discordId}");

        Log::info('[DiscordController@handleRegistroStep2] Última página — despachando ProcessRegistroStep2Job', [
            'discord_id'  => $discordId,
            'fields_sent' => count($accumulated),
        ]);

        // type:5 — Deferred ephemeral: cierra el modal de inmediato y muestra
        // un indicador de carga hasta que SendRegistroSuccessMessageJob envíe el follow-up.
        // (type:6 solo es válido para componentes, no para modal submits.)
        return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
    }

    /**
     * Botón "Continuar" entre páginas del Modal Step 2 — abre la siguiente página (type:9).
     * custom_id format: btn_registro_step2_continuar:{archetypeId}:{pageIndex}
     */
    private function handleRegistroStep2Continue(array $interaction, string $customId): JsonResponse
    {
        $parts       = explode(':', $customId);
        $archetypeId = ($parts[1] ?? '') ?: null;
        $pageIndex   = (int) ($parts[2] ?? 0);
        $discordId   = $this->extractDiscordId($interaction);

        Log::debug('[DiscordController@handleRegistroStep2Continue] Continuando página', [
            'discord_id'  => $discordId,
            'archetype_id' => $archetypeId,
            'page_index'  => $pageIndex,
        ]);

        $accumulated = $discordId ? Cache::get("registro_step2_{$discordId}", []) : [];
        $pages       = $this->mutatorService->buildStep2ModalPages($archetypeId, $accumulated);
        $page        = $pages[$pageIndex] ?? [];
        $total       = count($pages);

        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => "mudrais_registro_step_2:{$pageIndex}:{$archetypeId}",
                'title'      => "Ficha de Arquetipo (Paso " . ($pageIndex + 1) . " de {$total})",
                'components' => $page,
            ],
        ]);
    }

    /**
     * Modal de ficha MUDRAIS — deferred update (type:6).
     */
    private function handleFichaModal(array $interaction, string $discordId, string $token): JsonResponse
    {
        $profileText = $this->extractModalValue($interaction, 'profile_text');

        Log::info('[DiscordController@handleFichaModal] Despachando ProcessFichaModalJob', [
            'discord_id'  => $discordId,
            'text_length' => strlen($profileText),
        ]);

        ProcessFichaModalJob::dispatch($discordId, $profileText, $token);

        return response()->json(['type' => 6]);
    }

    // =========================================================================
    // Message components (type 3) — Button Bridge
    // =========================================================================

    /**
     * Enruta clics de botón. Permite abrir modales (type:9) desde botones,
     * ya que Discord no permite abrirlos desde el submit de otro modal.
     */
    private function handleMessageComponent(array $interaction, string $token): JsonResponse
    {
        $customId  = $interaction['data']['custom_id'] ?? '';
        $discordId = $this->extractDiscordId($interaction);

        Log::info('[DiscordController@handleMessageComponent] Botón recibido', [
            'custom_id'  => $customId,
            'discord_id' => $discordId,
        ]);

        return match (true) {
            in_array($customId, ['btn_reg_hombre', 'btn_reg_mujer', 'btn_reg_otro']) => $this->handleSeleccionGenero($interaction, $customId),
            str_starts_with($customId, 'btn_abrir_modal_1_nuevo')   => $this->handleAbrirModal1Nuevo($interaction),
            str_starts_with($customId, 'btn_abrir_modal_1_edicion') => $this->handleAbrirModal1Edicion($interaction),
            $customId === 'btn_retry_modal_1'         => $this->handleRetryModal1($interaction),
            in_array($customId, ['btn_abrir_modal_2', 'mudrais_abrir_step_2'])    => $this->handleAbrirModal2($interaction),
            str_starts_with($customId, 'btn_registro_step2_continuar:')            => $this->handleRegistroStep2Continue($interaction, $customId),
            str_starts_with($customId, 'vault_approve:')        => $this->handleVaultApprove($interaction, $customId, $token),
            str_starts_with($customId, 'vault_reject:')         => $this->handleVaultReject($interaction, $customId, $token),
            str_starts_with($customId, 'vault_continue:')       => $this->handleVaultContinue($interaction, $customId),
            str_starts_with($customId, 'create_context_open:')  => $this->handleOpenContextModal($interaction, $customId),
            str_starts_with($customId, 'context_continue:')     => $this->handleContextContinue($interaction, $customId),
            str_starts_with($customId, 'context_config:')       => $this->handleContextConfig($interaction, $customId),
            str_starts_with($customId, 'activity_view:') => (function () use ($token, $customId) {
                $activityId = explode(':', $customId)[1] ?? null;
                if (!$activityId) {
                    return response()->json(['type' => 4, 'data' => ['content' => 'ID inválido.', 'flags' => 64]]);
                }
                \App\Jobs\Discord\ProcessViewActivityJob::dispatch($token, $activityId);
                return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
            })(),
            str_starts_with($customId, 'avatar_view:') => (function () use ($token, $customId) {
                $avatarId = explode(':', $customId)[1] ?? null;
                if (!$avatarId) {
                    return response()->json(['type' => 4, 'data' => ['content' => 'ID inválido.', 'flags' => 64]]);
                }
                \App\Jobs\Discord\ProcessViewAvatarJob::dispatch($token, $avatarId);
                return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
            })(),
            str_starts_with($customId, 'player_profile_view:') => (function () use ($token, $customId) {
                $profileId = explode(':', $customId)[1] ?? null;
                if (! $profileId) {
                    return response()->json(['type' => 4, 'data' => ['content' => 'ID inválido.', 'flags' => 64]]);
                }
                \App\Jobs\Discord\ProcessViewPlayerProfileJob::dispatch($token, $profileId);
                return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
            })(),
            default => $this->handleUnknownModal($customId),
        };
    }

    /**
     * El usuario seleccionó su género/pronombres en el embed introductorio.
     * Cachea la selección y abre el Modal Step 1 vacío de inmediato.
     */
    private function handleSeleccionGenero(array $interaction, string $customId): JsonResponse
    {
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? '';

        $gender = match ($customId) {
            'btn_reg_hombre' => 'Hombre',
            'btn_reg_mujer'  => 'Mujer',
            default          => 'Otro',
        };

        Log::info('[DiscordController@handleSeleccionGenero] Género seleccionado', [
            'discord_id' => $discordId,
            'gender'     => $gender,
        ]);

        if ($discordId) {
            Cache::put("registro_is_edit_{$discordId}", false, now()->addMinutes(30));
            Cache::put("registro_genero_{$discordId}", $gender, now()->addMinutes(30));

            if (! Cache::has("registro_archetype_{$discordId}")) {
                $this->resolveAndCacheArchetype($discordId, $guildId);
            }
        }

        return response()->json(['type' => 9, 'data' => RegistroModals::step1(prefill: ['genero' => $gender])]);
    }

    /**
     * Abre Modal Step 1 vacío para jugadores nuevos.
     * Si el Player ya tiene datos básicos (Player row existe), salta directamente
     * al Modal Step 2 para no pedirle que rellene información que ya tenemos.
     */
    private function handleAbrirModal1Nuevo(array $interaction): JsonResponse
    {
        $discordId   = $this->extractDiscordId($interaction);
        $customId    = $interaction['data']['custom_id'] ?? '';
        $parts       = explode(':', $customId);
        $archetypeId = $parts[1] ?? Cache::get("registro_archetype_{$discordId}");

        Log::debug('[DiscordController@handleAbrirModal1Nuevo] Evaluando ruta de registro', [
            'discord_id'   => $discordId,
            'archetype_id' => $archetypeId,
        ]);

        if ($discordId) {
            Cache::put("registro_is_edit_{$discordId}", false, now()->addMinutes(30));
            if ($archetypeId) {
                Cache::put("registro_archetype_{$discordId}", $archetypeId, now()->addMinutes(30));
            }

            // Si el Player ya existe, sus datos básicos están guardados.
            // Pre-cacheamos is_edit=false y saltamos directo al Step 2 (gratuito).
            $player = Player::where('discord_id', $discordId)->first();
            if ($player) {
                Log::info('[DiscordController@handleAbrirModal1Nuevo] Player existente — saltando a Step 2', [
                    'player_id'    => $player->id,
                    'archetype_id' => $archetypeId,
                ]);

                Cache::put("registro_step1_{$discordId}", [
                    'is_edit'     => false,
                    'nationality' => $player->nationality,
                ], now()->addMinutes(30));

                return $this->handleAbrirModal2($interaction);
            }
        }

        return response()->json(['type' => 9, 'data' => RegistroModals::step1()]);
    }

    /**
     * Abre Modal Step 1 pre-llenado para edición.
     * Establece flag is_edit=true en caché.
     * Query O(1) para obtener datos actuales del player.
     */
    private function handleAbrirModal1Edicion(array $interaction): JsonResponse
    {
        $discordId   = $this->extractDiscordId($interaction);
        $guildId     = $interaction['guild_id'] ?? '';
        $customId    = $interaction['data']['custom_id'] ?? '';
        $parts       = explode(':', $customId);
        $archetypeId = $parts[1] ?? $this->resolveAndCacheArchetype($discordId, $guildId);

        Log::debug('[DiscordController@handleAbrirModal1Edicion] Abriendo modal step1 pre-llenado', [
            'discord_id'   => $discordId,
            'archetype_id' => $archetypeId,
        ]);

        $prefill = [];

        if ($discordId) {
            Cache::put("registro_is_edit_{$discordId}", true, now()->addMinutes(30));
            if ($archetypeId) {
                Cache::put("registro_archetype_{$discordId}", $archetypeId, now()->addMinutes(30));
            }

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

    /**
     * Abre Modal Step 1 pre-llenado con el último input fallido (desde caché retry).
     */
    private function handleRetryModal1(array $interaction): JsonResponse
    {
        $discordId = $this->extractDiscordId($interaction);

        Log::debug('[DiscordController@handleRetryModal1] Abriendo modal step1 con datos de retry', [
            'discord_id' => $discordId,
        ]);

        $prefill = $discordId ? Cache::get("registro_retry_{$discordId}", []) : [];

        return response()->json(['type' => 9, 'data' => RegistroModals::step1(error: true, prefill: $prefill)]);
    }

    /**
     * Abre Modal Step 2 (página 0). Soporta más de 5 mutadores mediante paginación.
     * Si el jugador accede directamente al Modal 2 (sin Step 1), detecta edición y
     * escribe el caché necesario para que ProcessRegistroStep2Job pueda cobrar monedas.
     */
    private function handleAbrirModal2(array $interaction): JsonResponse
    {
        $discordId   = $this->extractDiscordId($interaction);
        $guildId     = $interaction['guild_id'] ?? '';
        $archetypeId = ($discordId ? Cache::get("registro_archetype_{$discordId}") : null)
            ?? $this->resolveAndCacheArchetype($discordId, $guildId);
        $prefill     = [];
        $player      = null;

        Log::debug('[DiscordController@handleAbrirModal2] Abriendo modal step2', [
            'discord_id'  => $discordId,
            'archetype_id' => $archetypeId,
        ]);

        if ($discordId) {
            $cached = Cache::get("registro_step1_{$discordId}", []);
            $isEdit = (bool) ($cached['is_edit'] ?? false);

            // Bug 4 Fix: Solo promover a is_edit=true si is_edit no fue seteado explícitamente en el cache
            // (esto indica un acceso directo al Modal 2 sin pasar por Step 1 del flujo nuevo/edición)
            $isEditWasExplicitlySet = array_key_exists('is_edit', $cached);

            if (! $isEditWasExplicitlySet) {
                $player = Player::where('discord_id', $discordId)->first();
                if ($player) {
                    $isEdit = true;
                    Cache::put("registro_step1_{$discordId}", array_merge($cached, [
                        'is_edit'     => true,
                        'nationality' => $cached['nationality'] ?? $player->nationality,
                    ]), now()->addMinutes(30));

                    Log::debug('[DiscordController@handleAbrirModal2] Edición directa detectada — caché step1 actualizado', [
                        'discord_id' => $discordId,
                    ]);
                }
            }

            if ($player === null) {
                $player = Player::where('discord_id', $discordId)->first();
            }

            if ($player) {
                // Obtener datos del perfil de arquetipo si existe
                $profile = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::where('player_id', $player->id)
                    ->where('archetype_id', $archetypeId)
                    ->first();

                if ($profile) {
                    $prefill = array_filter([
                        'red_lines'        => $profile->red_lines    ? implode(', ', $profile->red_lines)    : null,
                        'yellow_lines'     => $profile->yellow_lines ? implode(', ', $profile->yellow_lines) : null,
                        'preferences'      => $profile->positive_prefs ? implode(', ', $profile->positive_prefs) : null,
                        'style'            => $profile->preference_profile ?? $profile->raw_profile,
                    ], fn ($v) => $v !== null && $v !== '');
                }
            }
        }

        $pages     = $this->mutatorService->buildStep2ModalPages($archetypeId, $prefill);
        $firstPage = $pages[0] ?? [];
        $total     = count($pages);
        $suffix    = $total > 1 ? ' (Paso 1 de ' . $total . ')' : '';

        Log::debug('[DiscordController@handleAbrirModal2] Páginas calculadas', [
            'discord_id' => $discordId,
            'total_pages' => $total,
        ]);

        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => "mudrais_registro_step_2:0:{$archetypeId}",
                'title'      => 'Ficha de Arquetipo' . $suffix,
                'components' => $firstPage,
            ],
        ]);
    }

    // =========================================================================
    // /create_context — lista + formulario multi-paso
    // =========================================================================

    /**
     * /create — respuesta inmediata (type:4 efímera).
     *
     * 1. Detecta el canal para resolver el Vault activo (Vault.id = channel_id).
     * 2. Del Vault extrae el archetype_id y valida que el tipo seleccionado pertenezca al mismo.
     * 3. Lista TODOS los elementos del tipo en el vault (visión global del vault).
     * 4. Devuelve el embed con la lista + botón para abrir el formulario de creación.
     */
    private function handleCreateContextCommand(array $interaction): JsonResponse
    {
        $entityTypeId = $this->extractOptionValue($interaction, 'type');
        $channelId    = $interaction['channel_id'] ?? null;

        Log::info('[DiscordController@handleCreateContextCommand] Resolviendo vault desde canal', [
            'entity_type_id' => $entityTypeId,
            'channel_id'     => $channelId,
        ]);

        // ── Resolver Vault desde el canal ─────────────────────────────────────
        $vault = $channelId
            ? \App\Domains\Narrative\Models\Vault::where('discord_channel_id', $channelId)->first()
            : null;

        if (! $vault) {
            Log::warning('[DiscordController@handleCreateContextCommand] Canal no corresponde a un Vault registrado', [
                'channel_id' => $channelId,
            ]);
            return $this->ephemeralError('⚠️ Este canal no pertenece a ningún Vault activo. Usa el comando desde el canal del Vault.');
        }

        $vaultArchetypeId = $vault->primaryArchetype()?->id;

        // ── Validar tipo de contexto ───────────────────────────────────────────
        $entityType = ArchetypeEntityType::with('archetype')->find($entityTypeId);

        if (! $entityType) {
            Log::warning('[DiscordController@handleCreateContextCommand] ArchetypeEntityType no encontrado', [
                'entity_type_id' => $entityTypeId,
            ]);
            return $this->ephemeralError('⚠️ Tipo inválido. Selecciona una opción del autocomplete.');
        }

        if ($entityType->archetype_id !== $vaultArchetypeId) {
            Log::warning('[DiscordController@handleCreateContextCommand] Tipo no pertenece al arquetipo del vault', [
                'entity_type_archetype' => $entityType->archetype_id,
                'vault_archetype'       => $vaultArchetypeId,
            ]);
            return $this->ephemeralError('⚠️ El tipo seleccionado no corresponde al arquetipo de este Vault.');
        }

        // ── Listar todos los elementos del tipo en este vault ─────────────────
        $items = Avatar::where('vault_id', $vault->id)
            ->where('archetype_entity_type_id', $entityTypeId)
            ->orderBy('name')
            ->get(['id', 'name']);

        Log::debug('[DiscordController@handleCreateContextCommand] Elementos encontrados en vault', [
            'vault_id'       => $vault->id,
            'entity_type_id' => $entityTypeId,
            'count'          => $items->count(),
        ]);

        if ($items->isEmpty()) {
            $description = "No hay **{$entityType->type_label}** en este Vault todavía.\n¡Sé el primero en crear uno!";
        } else {
            $lines       = $items->map(fn ($a) => "• {$a->name}")->implode("\n");
            $description = "**{$items->count()}** elemento(s) en este Vault:\n\n{$lines}";
        }

        return response()->json([
            'type' => 4,
            'data' => [
                'flags'  => 64,
                'embeds' => [[
                    'title'       => "{$entityType->type_label} — {$vault->name}",
                    'description' => $description,
                    'color'       => 0x5865F2,
                    'footer'      => ['text' => "Arquetipo: {$entityType->archetype->name}"],
                ]],
                'components' => [[
                    'type'       => 1,
                    'components' => [[
                        'type'      => 2,
                        'style'     => 1,
                        'label'     => "Crear {$entityType->type_label} →",
                        'custom_id' => "create_context_open:{$entityTypeId}:{$vault->id}",
                    ]],
                ]],
            ],
        ]);
    }

    /**
     * Botón "Crear [tipo]" — abre la primera página del modal (type:9).
     * custom_id format: create_context_open:<entityTypeId>:<vaultId>
     */
    private function handleOpenContextModal(array $interaction, string $customId): JsonResponse
    {
        $parts        = explode(':', $customId);
        $entityTypeId = $parts[1] ?? '';
        $vaultId      = $parts[2] ?? null;
        $discordId    = $this->extractDiscordId($interaction);

        Log::info('[DiscordController@handleOpenContextModal] Abriendo modal de contexto', [
            'entity_type_id' => $entityTypeId,
            'vault_id'       => $vaultId,
            'discord_id'     => $discordId,
        ]);

        if ($discordId) {
            Cache::forget("context_onboarding_{$discordId}");
        }

        $pages     = $this->mutatorService->buildContextModalPages($entityTypeId);
        $firstPage = $pages[0] ?? [];
        $total     = count($pages);
        $suffix    = $total > 1 ? ' (Paso 1 de ' . $total . ')' : '';

        // vault_id viaja en el custom_id para que el submit lo tenga disponible sin queries
        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => "create_context_modal:{$entityTypeId}:{$vaultId}:0",
                'title'      => 'Nuevo Contexto' . $suffix,
                'components' => $firstPage,
            ],
        ]);
    }

    /**
     * Botón "Continuar" entre páginas del modal de contexto — abre la siguiente página (type:9).
     * custom_id format: context_continue:<entityTypeId>:<vaultId>:<pageIndex>
     */
    private function handleContextContinue(array $interaction, string $customId): JsonResponse
    {
        $parts        = explode(':', $customId);
        $entityTypeId = $parts[1] ?? '';
        $vaultId      = $parts[2] ?? null;
        $pageIndex    = (int) ($parts[3] ?? 0);
        $discordId    = $this->extractDiscordId($interaction);

        Log::debug('[DiscordController@handleContextContinue] Continuando página de modal de contexto', [
            'entity_type_id' => $entityTypeId,
            'vault_id'       => $vaultId,
            'page_index'     => $pageIndex,
        ]);

        $cached         = $discordId ? Cache::get("context_onboarding_{$discordId}", []) : [];
        $pages          = $this->mutatorService->buildContextModalPages($entityTypeId, $cached);
        $pageComponents = $pages[$pageIndex] ?? [];
        $total          = count($pages);

        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => "create_context_modal:{$entityTypeId}:{$vaultId}:{$pageIndex}",
                'title'      => "Nuevo Contexto (Paso " . ($pageIndex + 1) . " de {$total})",
                'components' => $pageComponents,
            ],
        ]);
    }

    /**
     * Botón "Configurar Atributos" — abre el primer modal de configuración (Step 2 del arquetipo).
     */
    private function handleContextConfig(array $interaction, string $customId): JsonResponse
    {
        $avatarId = explode(':', $customId)[1] ?? null;
        $avatar   = Avatar::find($avatarId);

        if (! $avatar) {
            return $this->ephemeralError('No se encontró el personaje.');
        }

        Log::info('[DiscordController@handleContextConfig] Abriendo config para avatar', [
            'avatar_id'   => $avatar->id,
            'archetype_id' => $avatar->entityType->archetype_id ?? null,
        ]);

        $archetypeId = $avatar->entityType->archetype_id ?? null;

        // Usamos la lógica de registro step 2 para la configuración de atributos
        $pages = $this->mutatorService->buildStep2ModalPages($archetypeId, $avatar->content_raw);

        if (empty($pages)) {
            return $this->ephemeralError('Este tipo de personaje no tiene atributos configurables.');
        }

        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => "mudrais_registro_step_2:0", // Reusamos el submit del registro
                'title'      => 'Configurar Atributos',
                'components' => $pages[0] ?? [],
            ],
        ]);
    }

    /**
     * Modal submit de creación de contexto.
     * custom_id format: create_context_modal:<entityTypeId>:<vaultId>:<pageIndex>
     *
     * Si hay más páginas: persiste valores en caché y devuelve botón "Continuar".
     * Si es la última página: despacha ProcessCreateContextJob con vault_id explícito.
     */
    private function handleCreateContextModal(
        array   $interaction,
        string  $customId,
        string  $discordId,
        ?string $guildId,
        string  $token
    ): JsonResponse {
        $parts        = explode(':', $customId);
        $entityTypeId = $parts[1] ?? '';
        $vaultId      = $parts[2] ?? null;
        $pageIndex    = (int) ($parts[3] ?? 0);

        Log::info('[DiscordController@handleCreateContextModal] Modal de contexto recibido', [
            'entity_type_id' => $entityTypeId,
            'vault_id'       => $vaultId,
            'page_index'     => $pageIndex,
            'discord_id'     => $discordId,
        ]);

        $values = $this->extractModalValues($interaction['data']['components'] ?? []);

        $cached = Cache::get("context_onboarding_{$discordId}", []);
        $cached = array_merge($cached, $values);
        Cache::put("context_onboarding_{$discordId}", $cached, now()->addMinutes(30));

        $pages         = $this->mutatorService->buildContextModalPages($entityTypeId, $cached);
        $nextPageIndex = $pageIndex + 1;

        if ($nextPageIndex < count($pages)) {
            Log::debug('[DiscordController@handleCreateContextModal] Más páginas disponibles, mostrando botón continuar', [
                'next_page' => $nextPageIndex,
                'total'     => count($pages),
            ]);

            return response()->json([
                'type' => 4,
                'data' => [
                    'flags'   => 64,
                    'content' => '✅ Parte ' . ($pageIndex + 1) . ' completada. Haz clic abajo para continuar.',
                    'components' => [[
                        'type'       => 1,
                        'components' => [[
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => 'Continuar (Paso ' . ($nextPageIndex + 1) . ' de ' . count($pages) . ') →',
                            'custom_id' => "context_continue:{$entityTypeId}:{$vaultId}:{$nextPageIndex}",
                        ]],
                    ]],
                ],
            ]);
        }

        $contextName = $cached['context_name'] ?? '';
        $contentRaw  = array_diff_key($cached, array_flip(['context_name']));

        Log::info('[DiscordController@handleCreateContextModal] Última página — despachando ProcessCreateContextJob', [
            'entity_type_id' => $entityTypeId,
            'vault_id'       => $vaultId,
            'context_name'   => $contextName,
            'fields_count'   => count($contentRaw),
        ]);

        ProcessCreateContextJob::dispatch(
            $token,
            $discordId,
            $vaultId ?? '',
            $entityTypeId,
            $contextName,
            $contentRaw
        );

        Cache::forget("context_onboarding_{$discordId}");

        return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
    }

    private function handleVaultApprove(array $interaction, string $customId, string $token): JsonResponse
    {
        $pendingId = explode(':', $customId, 2)[1] ?? '';
        $discordId = $this->extractDiscordId($interaction) ?? '';

        Log::info('[DiscordController@handleVaultApprove] Aprobando vault', ['pending_id' => $pendingId]);

        \App\Jobs\Discord\ProcessVaultApprovalJob::dispatch($pendingId, $token, $discordId);

        return response()->json([
            'type' => 7,
            'data' => \App\Infrastructure\Discord\Embeds\VaultApprovalEmbeds::processing(),
        ]);
    }

    private function handleVaultReject(array $interaction, string $customId, string $token): JsonResponse
    {
        $pendingId = explode(':', $customId, 2)[1] ?? '';

        Log::info('[DiscordController@handleVaultReject] Rechazando vault', ['pending_id' => $pendingId]);

        Cache::forget("vault_pending_{$pendingId}");

        return response()->json([
            'type' => 7,
            'data' => \App\Infrastructure\Discord\Embeds\VaultApprovalEmbeds::rejected(),
        ]);
    }

    // =========================================================================
    // Error handlers
    // =========================================================================

    private function handleUnknownType(int $type): JsonResponse
    {
        Log::warning('[DiscordController@handle] Tipo de interacción desconocido', ['type' => $type]);
        return response()->json(['error' => 'Tipo desconocido'], 400);
    }

    private function handleUnknownCommand(string $name): JsonResponse
    {
        Log::warning('[DiscordController@handleSlashCommand] Comando desconocido', ['command' => $name]);
        return response()->json(['error' => 'Comando desconocido'], 400);
    }

    private function handleUnknownModal(string $customId): JsonResponse
    {
        Log::warning('[DiscordController@handleModalSubmit] Modal desconocido', ['custom_id' => $customId]);
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

    private function deferAndDispatch(callable $dispatch, bool $ephemeral = false): JsonResponse
    {
        $dispatch();
        $data = $ephemeral ? ['flags' => 64] : new \stdClass();
        return response()->json(['type' => 5, 'data' => $data]);
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

    /**
     * Extrae los valores de un modal submit soportando dos estructuras:
     *
     *   type:1  (Action Row)  → row['components'][n]['value' | 'values']
     *   type:18 (Section)     → row['component']['value' | 'values']
     *
     * Para selects (type:3) el valor viene en 'values' (array); para text inputs
     * (type:4) viene en 'value' (string).
     *
     * @param  list<array<string, mixed>> $components
     * @return array<string, string|null>
     */
    private function extractModalValues(array $components): array
    {
        $values = [];

        foreach ($components as $row) {
            // ── type:1 Action Row ──────────────────────────────────────────────
            foreach ($row['components'] ?? [] as $component) {
                $key = $component['custom_id'] ?? null;
                if ($key) {
                    $values[$key] = $component['values'][0]
                                 ?? $component['value']
                                 ?? null;
                }
            }

            // ── type:18 Section (componente singular) ──────────────────────────
            $section = $row['component'] ?? null;
            if ($section && isset($section['custom_id'])) {
                $key = $section['custom_id'];
                $values[$key] = $section['values'][0]
                             ?? $section['value']
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
                    return $component['values'][0]
                        ?? $component['value']
                        ?? '';
                }
            }
        }
        return '';
    }

    private function extractOptionValue(array $interaction, string $optionName): ?string
    {
        foreach ($interaction['data']['options'] ?? [] as $option) {
            if ($option['name'] === $optionName) {
                return (string) ($option['value'] ?? null);
            }
        }
        return null;
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
            $archetype = $this->archetypeResolver->resolveFromGuild($guildId);
            if ($discordId && $archetype) {
                Cache::put("registro_archetype_{$discordId}", $archetype->id, now()->addMinutes(30));
            }
            return $archetype?->id;
        } catch (\Throwable $e) {
            Log::warning('[DiscordController@resolveAndCacheArchetype] No se pudo resolver arquetipo', [
                'guild_id' => $guildId,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // /buscar-actividad (type 2 → deferred type:5 → ProcessBuscarActividadJob)
    // =========================================================================

    /**
     * /buscar-actividad [texto:<text>] [contexto:<avatar_id>]
     *
     * Opciones opcionales:
     *   texto    — texto libre de búsqueda (string, sin autocomplete)
     *   contexto — avatar del jugador para enriquecer la firma semántica (autocomplete)
     *
     * Responde type:5 (deferred) y despacha ProcessBuscarActividadJob.
     */
    private function handleBuscarActividadCommand(array $interaction, string $token): JsonResponse
    {
        $options   = $interaction['data']['options'] ?? [];
        $texto     = collect($options)->firstWhere('name', 'texto')['value'] ?? null;
        $contextoId = collect($options)->firstWhere('name', 'contexto')['value'] ?? null;
        $channelId = $interaction['channel_id'] ?? null;
        $discordId = $this->extractDiscordId($interaction);
        $guildId   = $interaction['guild_id'] ?? null;

        Log::info('[DiscordController@handleBuscarActividadCommand] Iniciando búsqueda', [
            'discord_id'  => $discordId,
            'channel_id'  => $channelId,
            'contexto_id' => $contextoId,
            'texto'       => $texto,
        ]);

        if (! $discordId) {
            return $this->ephemeralError('No se pudo identificar tu usuario.');
        }

        \App\Jobs\Discord\ProcessBuscarActividadJob::dispatch(
            $token,
            $discordId,
            $channelId,
            $guildId,
            $texto,
            $contextoId,
        );

        return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
    }

    // =========================================================================
    // /actividad crear (type 2 → type 9 modal)
    // =========================================================================

    /**
     * /actividad crear — valida vault + tipo de actividad, cachea ctx IDs y abre Modal.
     * Los IDs de contexto viajan en caché (no en custom_id) para evitar el límite de 100 chars.
     */
    private function handleActividadCommand(array $interaction): JsonResponse
    {
        $subOptions = $interaction['data']['options'][0]['options'] ?? [];
        $ctx1Id     = collect($subOptions)->firstWhere('name', 'contexto_principal')['value'] ?? null;
        $ctx2Id     = collect($subOptions)->firstWhere('name', 'contexto_secundario')['value'] ?? null;
        $channelId  = $interaction['channel_id'] ?? null;
        $discordId  = $this->extractDiscordId($interaction);

        Log::info('[DiscordController@handleActividadCommand] Iniciando', [
            'discord_id' => $discordId,
            'channel_id' => $channelId,
            'ctx1_id'    => $ctx1Id,
            'ctx2_id'    => $ctx2Id,
        ]);

        $vault = $channelId ? \App\Domains\Narrative\Models\Vault::where('discord_channel_id', $channelId)->first() : null;
        if (! $vault) {
            Log::warning('[DiscordController@handleActividadCommand] Canal sin Vault', [
                'channel_id' => $channelId,
            ]);
            return $this->ephemeralError('⚠️ Este canal no pertenece a ningún Vault activo. Usa el comando desde el canal del Vault.');
        }

        $activityType = ArchetypeEntityType::where('archetype_id', $vault->primaryArchetype()?->id)
            ->where('entity', 'activity')
            ->where('is_active', true)
            ->first();

        if (! $activityType) {
            Log::warning('[DiscordController@handleActividadCommand] Sin ArchetypeEntityType de actividad', [
                'vault_id'     => $vault->id,
                'archetype_id' => $vault->primaryArchetype()?->id,
            ]);
            return $this->ephemeralError('⚠️ Este Vault no tiene tipos de actividad configurados. Contacta a un administrador.');
        }

        if ($discordId) {
            Cache::put("actividad_ctx_{$discordId}", [
                'vault_id'         => $vault->id,
                'activity_type_id' => $activityType->id,
                'ctx1_id'          => $ctx1Id,
                'ctx2_id'          => $ctx2Id,
            ], now()->addMinutes(30));
        }

        Log::debug('[DiscordController@handleActividadCommand] Contexto cacheado, abriendo modal', [
            'vault_id'         => $vault->id,
            'activity_type_id' => $activityType->id,
        ]);

        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id'  => 'actividad_modal',
                'title'      => 'Nueva Actividad — ' . mb_substr($vault->name, 0, 40),
                'components' => [
                    [
                        'type'       => 1,
                        'components' => [[
                            'type'        => 4,
                            'custom_id'   => 'titulo',
                            'label'       => '¿Qué estás buscando?',
                            'style'       => 1,
                            'placeholder' => 'Ej: Busco tanque para mazmorra épica',
                            'min_length'  => 5,
                            'max_length'  => 100,
                            'required'    => true,
                        ]],
                    ],
                    [
                        'type'       => 1,
                        'components' => [[
                            'type'        => 4,
                            'custom_id'   => 'extra_context',
                            'label'       => 'Contexto Extra (Opcional)',
                            'style'       => 2,
                            'placeholder' => 'Ej: Fines de semana 8pm, nivel 80+...',
                            'required'    => false,
                        ]],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Modal submit de actividad_modal — despacha ProcessCreateActividadJob.
     */
    private function handleActividadModal(array $interaction, string $discordId, string $token): JsonResponse
    {
        $titulo       = $this->extractModalValue($interaction, 'titulo');
        $extraContext = $this->extractModalValue($interaction, 'extra_context');
        $cached       = Cache::get("actividad_ctx_{$discordId}", []);

        Log::info('[DiscordController@handleActividadModal] Modal submit recibido', [
            'discord_id'  => $discordId,
            'titulo'      => $titulo,
            'cached_keys' => array_keys($cached),
        ]);

        if (empty($cached['vault_id']) || empty($cached['activity_type_id'])) {
            Log::warning('[DiscordController@handleActividadModal] Caché expirada', [
                'discord_id' => $discordId,
            ]);
            return $this->ephemeralError('⏳ La sesión expiró. Repite el comando `/actividad crear`.');
        }

        \App\Jobs\Discord\ProcessCreateActividadJob::dispatch(
            $token,
            $discordId,
            $cached['vault_id'],
            $cached['activity_type_id'],
            $titulo,
            $extraContext ?? '',
            $cached['ctx1_id'] ?? null,
            $cached['ctx2_id'] ?? null,
        );

        Cache::forget("actividad_ctx_{$discordId}");

        Log::info('[DiscordController@handleActividadModal] ProcessCreateActividadJob despachado', [
            'discord_id' => $discordId,
            'vault_id'   => $cached['vault_id'],
        ]);

        return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
    }
}
