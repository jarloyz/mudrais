<?php

namespace App\Jobs;

use App\Application\Services\QdrantService;
use App\Domains\Narrative\Models\Avatar;
use App\Enums\IndexingStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paso final del pipeline de indexación de avatares.
 * Corre cuando todos los tags semánticos ya están normalizados en SQL.
 * Lee el vector y los tags de DB y hace UN SOLO upsert a Qdrant con todo listo.
 */
class FinalizeAvatarIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    public function __construct(public readonly string $avatarId)
    {
        $this->onQueue('tags');
    }

    public function handle(QdrantService $qdrant): void
    {
        Log::info('[FinalizeAvatarIndexJob] Inicio.', ['avatar_id' => $this->avatarId]);

        $avatar = Avatar::with(['ownerProfile', 'vault', 'entityType'])->find($this->avatarId);

        if (! $avatar) {
            Log::warning('[FinalizeAvatarIndexJob] Avatar no encontrado.', ['avatar_id' => $this->avatarId]);
            return;
        }

        $vector = $avatar->avatar_context_vector;

        if (empty($vector)) {
            Log::error('[FinalizeAvatarIndexJob] avatar_context_vector vacío — IndexAvatarJob no guardó el vector.', [
                'avatar_id' => $this->avatarId,
            ]);
            $avatar->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'Vector no disponible en FinalizeAvatarIndexJob',
            ]);
            return;
        }

        $tagIds = $avatar->tags()
            ->wherePivot('tag_context', 'semantic')
            ->pluck('canonical_tags.id')
            ->all();

        $vault       = $avatar->vault;
        $guildIds    = $vault ? [$vault->id] : [];
        $archetypeId = $avatar->ownerProfile?->archetype_id
            ?? $avatar->entityType?->archetype_id;

        $qdrantId = $avatar->avatar_hub_qdrant_id ?: (string) Str::uuid();

        $payload = [
            'entity_type'      => 'avatar',
            'avatar_id'        => $avatar->id,
            'owner_profile_id' => $avatar->owner_profile_id,
            'guild_ids'        => $guildIds,
            'archetype_id'     => $archetypeId,
            'is_lfg'           => (bool) $avatar->is_lfg,
            'tags'             => $tagIds,
        ];

        Log::info('[FinalizeAvatarIndexJob] Enviando punto a Qdrant.', [
            'avatar_id'   => $avatar->id,
            'qdrant_id'   => $qdrantId,
            'tags_count'  => count($tagIds),
            'vector_dims' => count($vector),
        ]);

        try {
            $success = $qdrant->upsertHubPoint($qdrantId, ['avatar_context' => $vector], $payload);
        } catch (\Throwable $e) {
            Log::error('[FinalizeAvatarIndexJob] Excepción durante upsert a Qdrant.', [
                'avatar_id' => $avatar->id,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            $avatar->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => '[upsert exception] ' . $e->getMessage(),
            ]);
            return;
        }

        if ($success) {
            $avatar->update([
                'avatar_hub_qdrant_id' => $qdrantId,
                'is_hub_indexed'       => true,
                'indexing_status'      => IndexingStatus::Indexed,
                'index_error'          => null,
            ]);
            Log::info('[FinalizeAvatarIndexJob] Avatar indexado correctamente en matchmaking_hub.', [
                'avatar_id'  => $avatar->id,
                'qdrant_id'  => $qdrantId,
                'tags_count' => count($tagIds),
            ]);
        } else {
            $avatar->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'Qdrant upsertHubPoint devolvió false',
            ]);
            Log::error('[FinalizeAvatarIndexJob] upsert devolvió false — revisar QdrantService.', [
                'avatar_id'  => $avatar->id,
                'qdrant_id'  => $qdrantId,
                'vector_dim' => count($vector),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Avatar::find($this->avatarId)?->update([
            'indexing_status' => IndexingStatus::Failed,
            'index_error'     => '[FinalizeAvatarIndexJob crash] ' . $e->getMessage(),
        ]);

        Log::error('[FinalizeAvatarIndexJob] Job terminado por excepción no controlada.', [
            'avatar_id' => $this->avatarId,
            'error'     => $e->getMessage(),
        ]);
    }
}
