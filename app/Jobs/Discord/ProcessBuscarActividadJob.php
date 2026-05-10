<?php

namespace App\Jobs\Discord;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Enums\ActivityStatus;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBuscarActividadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public int $timeout = 120;

    public function __construct(
        private string  $token,
        private string  $discordId,
        private ?string $channelId,
        private ?string $guildId,
        private ?string $texto,
        private ?string $contextoId,
    ) {
        $this->onQueue('high');
    }

    public function handle(QdrantService $qdrant, EmbeddingGateway $gateway): void
    {
        Log::info('[ProcessBuscarActividadJob] Iniciando búsqueda multi-vector', [
            'discord_id'   => $this->discordId,
            'channel_id'   => $this->channelId,
            'contexto_id'  => $this->contextoId,
            'texto_length' => strlen($this->texto ?? ''),
        ]);

        // ── 1. Resolver jugador y perfil ─────────────────────────────────────
        $player = Player::where('discord_id', $this->discordId)->first();
        if (! $player) {
            Log::warning('[ProcessBuscarActividadJob] Player no encontrado', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, '⚠️ No se encontró tu perfil. Usa `/registro` primero.', [], true);
            return;
        }

        // Resolver arquetipo desde el canal (vault) o desde la guild
        $archetype = $this->resolveArchetype();
        if (! $archetype) {
            Log::warning('[ProcessBuscarActividadJob] No se pudo resolver arquetipo', [
                'channel_id' => $this->channelId,
                'guild_id'   => $this->guildId,
            ]);
            $this->sendFollowUp($this->token, '⚠️ No se pudo determinar el arquetipo de este servidor.', [], true);
            return;
        }

        $profile = PlayerArchetypeProfile::where('player_id', $player->id)
            ->where('archetype_id', $archetype->id)
            ->first();

        if (! $profile || empty($profile->player_style_vector)) {
            Log::warning('[ProcessBuscarActividadJob] Sin perfil o vector de jugador', [
                'player_id'    => $player->id,
                'archetype_id' => $archetype->id,
                'has_profile'  => (bool) $profile,
            ]);
            $this->sendFollowUp(
                $this->token,
                '⚠️ Tu perfil aún no está indexado. Completa `/ficha` primero.',
                [],
                true
            );
            return;
        }

        // ── 2. Construir firma de búsqueda ───────────────────────────────────
        $playerVector = $profile->player_style_vector;

        $ctxAvatar    = $this->contextoId ? Avatar::find($this->contextoId) : null;
        $ctxVector    = $ctxAvatar?->avatar_context_vector ?: null;

        $textVector = null;
        if (filled($this->texto)) {
            $model      = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');
            $textVector = $gateway->embed($model, $this->texto);
            if (empty($textVector)) {
                Log::warning('[ProcessBuscarActividadJob] Embedding de texto vacío, se omite', [
                    'texto' => $this->texto,
                ]);
                $textVector = null;
            }
        }

        Log::debug('[ProcessBuscarActividadJob] Firma de búsqueda construida', [
            'has_player_vector' => ! empty($playerVector),
            'has_ctx_vector'    => ! empty($ctxVector),
            'has_text_vector'   => ! empty($textVector),
        ]);

        // ── 3. Filtros duros ─────────────────────────────────────────────────
        // Solo actividades del mismo arquetipo en estado RECRUITING
        $must = [
            ['key' => 'archetype_id', 'match' => ['value' => $archetype->id]],
            ['key' => 'status',       'match' => ['value' => ActivityStatus::RECRUITING->value]],
        ];

        // Excluir actividades del propio jugador
        $mustNot = [];

        // ── 4. Búsqueda ponderada multi-vector ───────────────────────────────
        // Los pesos se leen del arquetipo; si no están configurados, se usan defaults.
        // Los pesos se normalizan según qué vectores están disponibles para evitar
        // penalizar al buscador que no tiene texto o contexto.
        $rawWeights = $archetype->getSearchWeights();

        $activeVectors = array_filter([
            'player_style'   => $playerVector ?: null,
            'avatar_context' => $ctxVector ?: null,
            'activity_vibe'  => $textVector ?: null,
        ]);

        $activeWeights = array_intersect_key($rawWeights, $activeVectors);
        $weightSum     = array_sum($activeWeights);

        if ($weightSum <= 0) {
            Log::error('[ProcessBuscarActividadJob] Sin vectores disponibles para búsqueda');
            $this->sendFollowUp($this->token, '❌ No hay suficiente información para realizar la búsqueda.', [], true);
            return;
        }

        // Normalizar pesos para que sumen 1.0
        foreach ($activeWeights as $k => $v) {
            $activeWeights[$k] = $v / $weightSum;
        }

        Log::info('[ProcessBuscarActividadJob] Pesos normalizados', [
            'weights' => $activeWeights,
        ]);

        // Acumular scores ponderados por activity_id
        $scores = [];

        foreach ($activeVectors as $vectorName => $queryVector) {
            $weight  = $activeWeights[$vectorName];
            $results = $qdrant->searchHub($vectorName, $queryVector, $must, $mustNot, 50);

            Log::debug('[ProcessBuscarActividadJob] Resultados parciales', [
                'vector_name' => $vectorName,
                'weight'      => $weight,
                'count'       => count($results),
            ]);

            foreach ($results as $point) {
                $activityId = $point['payload']['activity_id'] ?? null;
                if (! $activityId) continue;

                $scores[$activityId] ??= 0.0;
                $scores[$activityId] += (float) $point['score'] * $weight;
            }
        }

        if (empty($scores)) {
            Log::info('[ProcessBuscarActividadJob] Sin resultados', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, '🔍 No se encontraron actividades compatibles en este servidor todavía.', [], true);
            return;
        }

        // Ordenar por score final descendente y tomar top 5
        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, 5);

        // ── 5. Cargar actividades y formatear embed ───────────────────────────
        $activities = Activity::with(['vault', 'creatorProfile.player'])
            ->whereIn('id', $topIds)
            ->get()
            ->keyBy('id');

        $fields = [];

        foreach ($topIds as $rank => $activityId) {
            $activity = $activities->get($activityId);
            if (! $activity) continue;

            $score      = round($scores[$activityId] * 100, 1);
            $posicion   = $rank + 1;
            $creatorPlayer = $activity->creatorProfile?->player;
            $creatorMention = $creatorPlayer
                ? "<@{$creatorPlayer->discord_id}>"
                : '*Desconocido*';

            $ctx1Name = $activity->content_raw['ctx1_name'] ?? null;
            $ctx2Name = $activity->content_raw['ctx2_name'] ?? null;
            $extra    = $activity->content_raw['extra_context'] ?? null;

            $contextLine = implode(' · ', array_filter([$ctx1Name, $ctx2Name]));
            $extraLine   = filled($extra) ? "\n> " . mb_strimwidth($extra, 0, 80, '…') : '';

            $fields[] = [
                'name'   => "#{$posicion} — {$activity->title}  ({$score}%)",
                'value'  => implode("\n", array_filter([
                    "🏰 **{$activity->vault?->name}** · 👤 {$creatorMention}",
                    $contextLine ? "🎭 {$contextLine}" : null,
                    $extraLine ?: null,
                ])),
                'inline' => false,
            ];
        }

        Log::info('[ProcessBuscarActividadJob] Resultados enviados', [
            'discord_id' => $this->discordId,
            'count'      => count($fields),
        ]);

        $this->sendFollowUp($this->token, '', [
            'embeds' => [[
                'title'       => '🎯 Actividades Compatibles',
                'description' => 'Ordenadas por afinidad semántica entre tu perfil, tu personaje y el texto de búsqueda.',
                'color'       => 0x5865F2,
                'fields'      => $fields,
                'footer'      => ['text' => "Arquetipo: {$archetype->name} · MUDRAIS Matchmaking"],
            ]],
        ], true);
    }

    /**
     * Resuelve el arquetipo desde el canal (vault) si existe, o desde la guild como fallback.
     */
    private function resolveArchetype(): ?Archetype
    {
        if ($this->channelId) {
            $vault = Vault::where('discord_channel_id', $this->channelId)->first();
            if ($vault) {
                $archetype = $vault->primaryArchetype();
                if ($archetype) {
                    Log::debug('[ProcessBuscarActividadJob] Arquetipo resuelto desde vault', [
                        'vault_id'     => $vault->id,
                        'archetype_id' => $archetype->id,
                    ]);
                    return $archetype;
                }
            }
        }

        if ($this->guildId) {
            $guild = \App\Domains\Community\Models\Guild::where('discord_guild_id', $this->guildId)->first();
            if ($guild) {
                $archetype = $guild->archetypes()->orderByPivot('is_primary', 'desc')->first();
                Log::debug('[ProcessBuscarActividadJob] Arquetipo resuelto desde guild', [
                    'guild_id'     => $this->guildId,
                    'archetype_id' => $archetype?->id,
                ]);
                return $archetype;
            }
        }

        return null;
    }
}
