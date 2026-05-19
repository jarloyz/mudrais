<?php

namespace App\Jobs\Discord;

use App\Application\Services\ArchetypeResolverService;
use App\Application\Services\GuildValidationService;
use App\Infrastructure\Ai\Agents\ProfileOptimizerAgent;
use App\Infrastructure\Ai\Agents\ProfileTranslatorAgent;
use App\Infrastructure\Discord\Embeds\RegistroEmbeds;
use App\Jobs\NormalizePlayerTagsJob;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Domains\Matchmaking\Enums\MutatorStorageMode;
use App\Models\GameItem;
use App\Models\Player;
use App\Models\PlayerArchetypeProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessRegistroStep2Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        public readonly string  $discordId,
        public readonly array   $data,
        public readonly string  $token,
        public readonly ?string $guildId    = null,
        public readonly ?string $username   = null,
        public readonly ?string $threadId   = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        ProfileTranslatorAgent $translator,
        ArchetypeResolverService $archetypeResolver,
        GuildValidationService $guildValidator,
        ProfileOptimizerAgent $optimizer,
        ArchetypeMutatorService $mutatorService,
    ): void
    {
        Log::info('[ProcessRegistroStep2Job] Iniciando', [
            'discord_id' => $this->discordId,
            'guild_id'   => $this->guildId,
        ]);

        // ── FRENO DE EMERGENCIA: Validar integridad de los datos recibidos ──
        $archetypeIdForValidation = Cache::get("registro_archetype_{$this->discordId}");
        $missing = [];
        if ($archetypeIdForValidation) {
            $mutators = $mutatorService->getFieldsForContext((string)$archetypeIdForValidation, 'registration');
            foreach ($mutators as $m) {
                $val = $this->data[$m->field_key] ?? '';
                if ($m->is_required && trim((string)$val) === '') {
                    $missing[] = $m->field_label;
                }
            }
        } else {
            if (trim($this->data['preferences'] ?? '') === '') $missing[] = 'Favorites';
            if (trim($this->data['style'] ?? '') === '') $missing[] = 'Style Summary';
        }

        if (!empty($missing)) {
            Log::error('[ProcessRegistroStep2Job] INTEGRIDAD VIOLADA: Job lanzado con campos vacíos', [
                'discord_id' => $this->discordId,
                'missing'    => $missing,
            ]);
            $this->sendFollowUp(
                $this->token,
                '⚠️ Error crítico: Tu perfil está incompleto (' . implode(', ', $missing) . '). Usa `/registro` de nuevo.',
                [],
                true
            );
            return;
        }

        $player = Player::where('discord_id', $this->discordId)->first();

        if (! $player) {
            Log::warning('[ProcessRegistroStep2Job] Player no encontrado', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, 'No tienes perfil registrado. Usa `/registro` primero.', [], true);
            return;
        }

        // Recupera datos del step1 (incluye flag is_edit)
        $cached      = Cache::get("registro_step1_{$this->discordId}", []);
        $nationality = $cached['nationality'] ?? $player->nationality ?? null;
        $isEdit      = (bool) ($cached['is_edit'] ?? false);

        Log::debug('[ProcessRegistroStep2Job] Cache step1 recuperado', [
            'player_id'   => $player->id,
            'nationality' => $nationality,
            'is_edit'     => $isEdit,
        ]);

        // ── Resolución de arquetipo B2B ────────────────────────────────────────
        $cachedArchetypeId = Cache::get("registro_archetype_{$this->discordId}");
        $archetype = null;

        if ($cachedArchetypeId) {
            $archetype = Archetype::find($cachedArchetypeId);
        }

        if (! $archetype && $this->guildId) {
            $archetype = $archetypeResolver->resolveFromGuild($this->guildId);
        }

        Log::debug('[ProcessRegistroStep2Job] Arquetipo resuelto', [
            'player_id'          => $player->id,
            'archetype_id'       => $archetype?->id,
            'cached_archetype'   => $cachedArchetypeId,
            'vector_name'        => $archetype?->qdrant_vector_name,
        ]);

        // ── Procesamiento de Mutadores Dinámicos ──────────────────────────────
        $mutatorData = [];
        $semanticMutators = [];
        $metadata = [
            'nationality'      => $nationality,
        ];

        if ($archetype) {
            $mutators = $mutatorService->getFieldsForContext($archetype->id, 'registration');
            foreach ($mutators as $m) {
                if (isset($this->data[$m->field_key])) {
                    $val = $this->data[$m->field_key];
                    $mutatorData[$m->field_key] = $val;

                    if ($m->storage_mode->storesRaw()) {
                        $metadata[$m->field_key] = $val;
                    }

                    if ($m->storage_mode->storesSemantic()) {
                        $semanticMutators[$m->field_key] = $val;
                    }
                }
            }
        }

        // ── Procesamiento de Campos Legacy (Fallback si no hay mutadores) ──────
        // Capturar texto original del usuario ANTES de traducir
        $rawRedLines    = $this->splitRaw($this->data['red_lines']       ?? '');
        $rawYellowLines = $this->splitRaw($this->data['yellow_lines']    ?? '');
        $rawAffinities  = $this->splitRaw($this->data['preferences']     ?? '');
        $style          = trim($this->data['style'] ?? '');
        $scheduleRaw    = trim($this->data['schedule_raw'] ?? '');

        // Traducir a inglés para normalización de tags
        $translated = $translator->translate([
            'red_lines'    => $rawRedLines,
            'yellow_lines' => $rawYellowLines,
            'affinities'   => $rawAffinities,
        ]);

        Log::debug('[ProcessRegistroStep2Job] Tags traducidos', [
            'player_id'    => $player->id,
            'red_count'    => count($translated['red_lines']    ?? []),
            'yellow_count' => count($translated['yellow_lines'] ?? []),
            'aff_count'    => count($translated['affinities']   ?? []),
        ]);

        // ── Optimizer: generar texto denso para embedding B2B ─────────────────
        $optimizedResult = ['optimized_text' => '', 'semantic_tag_query' => ''];
        // Combinamos datos básicos, campos legacy y mutadores semánticos
        $allDataForOptimizer = array_merge(
            $cached,
            $semanticMutators,
            [
                'red_lines'    => $translated['red_lines']    ?? [],
                'yellow_lines' => $translated['yellow_lines'] ?? [],
                'affinities'   => $translated['affinities']   ?? [],
                'style'        => $style,
                'schedule_raw' => $scheduleRaw ?: null,
            ]
        );

        try {
            $optimizedResult = $optimizer->optimize($allDataForOptimizer, $archetype, $player->id);
        } catch (\RuntimeException $e) {
            Log::warning('[ProcessRegistroStep2Job] Optimizer falló, se omite texto optimizado.', [
                'player_id' => $player->id,
                'error'     => $e->getMessage(),
            ]);
        }

        // Persistir perfil del jugador (tabla global players)
        $player->update(array_filter([
            'nationality' => $nationality,
        ], fn ($v) => $v !== null));

        // ── Persistir en player_archetype_profiles (tabla B2B) ────────────────
        $profile = null;

        if ($archetype) {
            $profile = PlayerArchetypeProfile::updateOrCreate(
                ['player_id' => $player->id, 'archetype_id' => $archetype->id],
                [
                    'discord_user_id'    => $this->discordId,
                    'positive_prefs'     => $translated['affinities'] ?? [],
                    'red_lines'          => $translated['red_lines'] ?? [],
                    'yellow_lines'       => $translated['yellow_lines'] ?? [],
                    'raw_profile'        => $style ?: null,
                    'preference_profile' => $style ?: null,
                    'schedule_raw'       => $scheduleRaw ?: null,
                    'metadata'           => $metadata,
                    'content_raw'        => $this->data,
                    'semantic_tag_query' => $optimizedResult['semantic_tag_query'] ?: null,
                    'is_vectorized'      => false,
                ]
            );

            if ($optimizedResult['optimized_text'] !== '') {
                $profile->saveOptimizedText($optimizedResult['optimized_text']);
            }

            // Registrar membresía en guild_profiles
            $guild = $guildValidator->findOrRegister($this->guildId);
            $guildValidator->ensureGuildProfile($guild, $this->discordId);

            Log::info('[ProcessRegistroStep2Job] player_archetype_profiles actualizado.', [
                'discord_id'   => $this->discordId,
                'archetype_id' => $archetype->id,
                'profile_id'   => $profile->id,
            ]);
        }

        // ── Economía: deducir monedas solo en edición ─────────────────────────
        if ($isEdit) {
            $this->processEditPayment($player);
        }

        if ($profile === null) {
            Log::error('[ProcessRegistroStep2Job] Arquetipo no resuelto — no se creó PlayerArchetypeProfile', [
                'discord_id' => $this->discordId,
                'guild_id'   => $this->guildId,
            ]);
            $this->sendFollowUp(
                $this->token,
                '⚠️ No se pudo determinar tu arquetipo. Inténtalo de nuevo con `/registro`.',
                [],
                true
            );
            return;
        }

        // SendRegistroSuccessMessageJob se pasa como continuation de NormalizePlayerTagsJob
        // para garantizar que se envíe DESPUÉS de que IndexPlayerStyleJob complete la indexación.
        $successJob = new SendRegistroSuccessMessageJob(
            token:      $this->token,
            discordId:  $this->discordId,
            isEdit:     $isEdit,
            username:   $this->username,
            threadId:   $this->threadId,
            guildId:    $this->guildId,
        );

        Bus::chain([
            new NormalizePlayerTagsJob(
                $profile,
                $translated['red_lines']    ?? [],
                $translated['yellow_lines'] ?? [],
                $translated['affinities']   ?? [],
                $rawRedLines,
                $rawYellowLines,
                $rawAffinities,
                $optimizedResult['semantic_tag_query'] ?? '',
                $successJob,
            ),
        ])->dispatch();

        Log::info('[ProcessRegistroStep2Job] Procesamiento completado. La cadena de jobs continuará asíncronamente.', [
            'player_id'  => $player->id,
            'discord_id' => $this->discordId,
            'is_edit'    => $isEdit,
        ]);

        // Limpiar cachés de flujo de registro tras éxito
        Cache::forget("registro_step1_{$this->discordId}");
        Cache::forget("registro_archetype_{$this->discordId}");
        Cache::forget("registro_is_edit_{$this->discordId}");
        Cache::forget("registro_genero_{$this->discordId}");
    }

    /**
     * Resuelve el ítem de edición para el guild actual y deduce las monedas.
     * Si falla (saldo insuficiente o ítem desactivado), solo loguea — no aborta el perfil.
     */
    private function processEditPayment(Player $player): void
    {
        Log::info('[ProcessRegistroStep2Job] Procesando cobro de edición', [
            'player_id' => $player->id,
            'guild_id'  => $this->guildId,
        ]);

        try {
            $effect = GameItem::resolveForGuild('registro_edit', $this->guildId ?? '');
            $cost   = abs($effect['coin_delta']);

            $player->deductCoins($cost, 'registro_edit', [
                'guild_id' => $this->guildId,
                'command'  => '/registro',
            ]);

            Log::info('[ProcessRegistroStep2Job] Monedas descontadas por edición', [
                'player_id' => $player->id,
                'cost'      => $cost,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ProcessRegistroStep2Job] No se pudo descontar monedas', [
                'player_id' => $player->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function splitRaw(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*[,;]\s*|\s+y\s+/iu', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
