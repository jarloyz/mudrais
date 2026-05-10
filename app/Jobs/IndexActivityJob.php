<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Services\EntityTypePromptBuilderService;
use App\Domains\Narrative\Models\Activity;
use App\Enums\IndexingStatus;
use App\Infrastructure\Ai\Agents\ContextOptimizerAgent;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexActivityJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly string $activityId
    ) {
        $this->onQueue('index');
    }

    public function handle(
        EmbeddingGateway $gateway,
        QdrantService $qdrant,
        StyleOptimizerAgent $styleOptimizer,
        ContextOptimizerAgent $contextOptimizer,
        EntityTypePromptBuilderService $promptBuilder
    ): void {
        $activity = Activity::with(['creatorProfile', 'vault', 'avatars', 'entityType.archetype'])->find($this->activityId);

        if (! $activity) {
            return;
        }

        $creatorProfile = $activity->creatorProfile;
        if (! $creatorProfile || empty($creatorProfile->player_style_vector)) {
            Log::warning("IndexActivityJob: activity {$activity->id} lacks creatorProfile with player_style_vector. Aborting.");
            $activity->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'creatorProfile sin player_style_vector — ejecuta IndexPlayerStyleJob primero',
            ]);
            return;
        }

        $vault = $activity->vault;
        if ($vault && empty($vault->vault_setting_vector)) {
            IndexVaultJob::dispatchSync($vault->id);
            $vault->refresh();
        }

        $avatar = $activity->avatars->first();
        if ($activity->requires_avatar && $avatar) {
            if (empty($avatar->avatar_context_vector)) {
                IndexAvatarJob::dispatchSync($avatar->id);
                $avatar->refresh();
            }
        }

        // Resolver contextos de /actividad crear (ctx1 y ctx2) desde content_raw.
        // Cada contexto puede ser un tipo distinto (personaje, locación, ítem…),
        // por eso se guardan en named vectors independientes en lugar de promediar.
        $ctx1Id     = $activity->content_raw['ctx1_id'] ?? null;
        $ctx2Id     = $activity->content_raw['ctx2_id'] ?? null;
        $ctx1Avatar = $ctx1Id ? \App\Domains\Narrative\Models\Avatar::find($ctx1Id) : null;
        $ctx2Avatar = $ctx2Id ? \App\Domains\Narrative\Models\Avatar::find($ctx2Id) : null;

        foreach (array_filter([$ctx1Avatar, $ctx2Avatar]) as $ctxAvatar) {
            if (empty($ctxAvatar->avatar_context_vector)) {
                IndexAvatarJob::dispatchSync($ctxAvatar->id);
                $ctxAvatar->refresh();
            }
        }

        $vectors = [
            'player_style'  => $creatorProfile->player_style_vector,
            'vault_setting' => $vault ? $vault->vault_setting_vector : [],
        ];

        if ($activity->requires_avatar && $avatar && ! empty($avatar->avatar_context_vector)) {
            $vectors['avatar_context'] = $avatar->avatar_context_vector;
        }

        // ctx1_context usa avatar_context
        if ($ctx1Avatar && ! empty($ctx1Avatar->avatar_context_vector)) {
            $vectors['avatar_context'] = $ctx1Avatar->avatar_context_vector;
            Log::debug('[IndexActivityJob] avatar_context añadido', [
                'activity_id' => $activity->id,
                'ctx1_id'     => $ctx1Id,
            ]);
        }

        $playerId = 0;
        if ($creatorProfile) {
            $player = \App\Domains\Community\Models\Player::where('discord_id', $creatorProfile->discord_user_id)->first();
            if ($player) {
                $playerId = $player->id;
            }
        }

        $entityType       = $activity->entityType;
        $optimizedText    = '';
        $semanticTagQuery = null;

        if ($entityType && filled($entityType->system_prompt) && filled($activity->content_raw)) {
            Log::info('[IndexActivityJob] Usando ContextOptimizer pipeline', [
                'activity_id'    => $activity->id,
                'entity_type_id' => $entityType->id,
            ]);

            $softFields = $promptBuilder->extractSoftFields($entityType, $activity->content_raw);

            // Si no hay mutadores configurados, usar activity_description como input semántico directo.
            if (empty($softFields) && filled($activity->activity_description)) {
                $softFields = ['Activity Post' => $activity->activity_description];
                Log::debug('[IndexActivityJob] Sin mutadores; usando activity_description como soft field', [
                    'activity_id' => $activity->id,
                ]);
            }

            $builtPrompt = $promptBuilder->buildPrompt($entityType, $softFields, $vault);

            if (filled($builtPrompt) && filled($softFields)) {
                try {
                    $result           = $contextOptimizer->optimize($builtPrompt, $playerId);
                    $optimizedText    = $result['optimized_text_en'];
                    $semanticTagQuery = $result['semantic_tag_query'];
                    $activity->saveOptimizedText($optimizedText);
                    $activity->update(['semantic_tag_query' => $semanticTagQuery]);
                } catch (\RuntimeException $e) {
                    Log::error('[IndexActivityJob] ContextOptimizer falló', [
                        'activity_id' => $activity->id,
                        'error'       => $e->getMessage(),
                        'trace'       => $e->getTraceAsString(),
                    ]);
                    $activity->update([
                        'indexing_status' => IndexingStatus::Failed,
                        'index_error'     => '[ContextOptimizer] ' . $e->getMessage(),
                    ]);
                    return;
                }
            } else {
                Log::warning('[IndexActivityJob] Sin prompt ni descripción; usando título como fallback', [
                    'activity_id' => $activity->id,
                ]);
                $optimizedText = $activity->title;
            }
        } else {
            Log::info('[IndexActivityJob] Usando StyleOptimizer pipeline (legacy)', [
                'activity_id' => $activity->id,
            ]);

            $textToOptimize = trim("Title: {$activity->title}\nObjective: {$activity->objective}\nDescription: {$activity->activity_description}");

            if ($textToOptimize !== '') {
                try {
                    $optimizedText = $styleOptimizer->optimize($textToOptimize, $playerId);
                } catch (\RuntimeException $e) {
                    Log::error('IndexActivityJob: optimizer failed.', [
                        'activity_id' => $activity->id,
                        'error'       => $e->getMessage(),
                    ]);
                    $activity->update([
                        'indexing_status' => IndexingStatus::Failed,
                        'index_error'     => '[StyleOptimizer] ' . $e->getMessage(),
                    ]);
                    return;
                }
            } else {
                Log::warning("IndexActivityJob: activity {$activity->id} has no text for vibe vector.");
                $activity->update([
                    'indexing_status' => IndexingStatus::Failed,
                    'index_error'     => 'Sin texto para indexar: título, objetivo y descripción vacíos',
                ]);
                return;
            }
        }

        $model = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');

        if (filled($optimizedText)) {
            $vibeVector = $gateway->embed($model, $optimizedText);

            if (! empty($vibeVector)) {
                $vectors['activity_vibe'] = $vibeVector;
            }
        }

        $guildIds    = $vault ? [$vault->id] : [];
        $archetypeId = $creatorProfile->archetype_id;

        // Embeber semantic_tag_query (generado por el optimizer) para búsqueda de tags.
        // Es más preciso que activity_vibe porque el LLM lo genera específicamente para taxonomía.
        // Si no hay semantic_tag_query (pipeline legacy), cae al activity_vibe.
        $tagSearchVector = null;

        if (filled($semanticTagQuery)) {
            $tagSearchVector = $gateway->embed($model, $semanticTagQuery);
            Log::debug('[IndexActivityJob] semantic_tag_query embebido para tag search', [
                'activity_id'        => $activity->id,
                'semantic_tag_query' => $semanticTagQuery,
            ]);
        } elseif (! empty($vectors['activity_vibe'])) {
            $tagSearchVector = $vectors['activity_vibe'];
            Log::debug('[IndexActivityJob] Usando activity_vibe para tag search (sin semantic_tag_query)', [
                'activity_id' => $activity->id,
            ]);
        }

        if (! empty($tagSearchVector)) {
            $nearestTags = $qdrant->searchTaxonomyTags($tagSearchVector, limit: 5, scoreThreshold: 0.75);
            $tagIds      = collect($nearestTags)->pluck('payload.canonical_tag_id')->filter()->values()->all();

            if (! empty($tagIds)) {
                $activity->canonicalTags()->syncWithoutDetaching($tagIds);
                Log::info('[IndexActivityJob] Canonical tags auto-asignados', [
                    'activity_id' => $activity->id,
                    'tag_ids'     => $tagIds,
                    'source'      => filled($semanticTagQuery) ? 'semantic_tag_query' : 'activity_vibe',
                ]);
            } else {
                Log::debug('[IndexActivityJob] No se encontraron canonical tags por encima del threshold', [
                    'activity_id' => $activity->id,
                ]);
            }
        }

        $tags = $activity->canonicalTags()->pluck('canonical_tags.id')->all();

        // Enriquecer payload con referencias a los contextos (para Plan 2 — búsqueda multi-vector)
        // Usar avatar_hub_qdrant_id del modelo re-fetch (no del snapshot en content_raw)
        $ctx1QdrantId = $ctx1Avatar?->avatar_hub_qdrant_id ?? null;
        $ctx2QdrantId = $ctx2Avatar?->avatar_hub_qdrant_id ?? null;

        $qdrantId = $activity->activity_hub_qdrant_id ?: (string) Str::uuid();

        // Enum status is backed by int
        $statusValue = $activity->status instanceof \App\Domains\Matchmaking\Enums\ActivityStatus
            ? $activity->status->value
            : (int) $activity->status;

        $payload = [
            'entity_type'        => 'activity',
            'activity_id'        => $activity->id,
            'archetype_id'       => $archetypeId,
            'guild_ids'          => $guildIds,
            'status'             => $statusValue,
            'requires_avatar'    => (bool) $activity->requires_avatar,
            'search_direction'   => $activity->search_direction?->value ?? 'outbound',
            'parent_activity_id' => $activity->parent_activity_id,
            'is_team_search'     => $activity->isTeamSearch(),
            'tags'               => $tags,
            'ctx1_qdrant_id'     => $ctx1QdrantId,
            'ctx2_qdrant_id'     => $ctx2QdrantId,
            'created_at'         => $activity->created_at?->timestamp,
        ];

        $activity->update(['indexing_status' => IndexingStatus::Processing]);

        try {
            $success = $qdrant->upsertHubPoint($qdrantId, $vectors, $payload);
        } catch (\Throwable $e) {
            Log::error('[IndexActivityJob] Excepción durante upsert a Qdrant.', [
                'activity_id' => $activity->id,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);
            $activity->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => '[upsert exception] ' . $e->getMessage(),
            ]);
            return;
        }

        if ($success) {
            $contentRaw                   = $activity->content_raw;
            $contentRaw['ctx1_qdrant_id'] = $ctx1QdrantId;
            $contentRaw['ctx2_qdrant_id'] = $ctx2QdrantId;

            $activity->update([
                'activity_hub_qdrant_id' => $qdrantId,
                'is_hub_indexed'         => true,
                'content_raw'            => $contentRaw,
                'indexing_status'        => IndexingStatus::Indexed,
                'index_error'            => null,
            ]);
            Log::info("IndexActivityJob: activity {$activity->id} indexed in matchmaking_hub.");
        } else {
            Log::error("IndexActivityJob: Qdrant sync failed for activity {$activity->id}.");
            $activity->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'Qdrant upsertHubPoint devolvió false',
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Activity::find($this->activityId)?->update([
            'indexing_status' => IndexingStatus::Failed,
            'index_error'     => '[crash] ' . $exception->getMessage(),
        ]);

        Log::error('[IndexActivityJob] Job terminado por excepción no controlada', [
            'activity_id' => $this->activityId,
            'error'       => $exception->getMessage(),
        ]);
    }
}
