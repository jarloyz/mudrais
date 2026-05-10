<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Matchmaking\Services\EntityTypePromptBuilderService;
use App\Enums\IndexingStatus;
use App\Infrastructure\Ai\Agents\ContextOptimizerAgent;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use App\Jobs\FinalizeAvatarIndexJob;
use App\Jobs\NormalizeAvatarTagsJob;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexAvatarJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly string $avatarId
    ) {
        $this->onQueue('index');
    }

    public function handle(
        EmbeddingGateway $gateway,
        StyleOptimizerAgent $styleOptimizer,
        ContextOptimizerAgent $contextOptimizer,
        EntityTypePromptBuilderService $promptBuilder
    ): void {
        $avatar = Avatar::with(['ownerProfile.tags', 'vault', 'bullets', 'entityType.archetype'])->find($this->avatarId);

        if (! $avatar) {
            return;
        }

        $vault = $avatar->vault;

        if ($vault && empty($vault->vault_setting_vector)) {
            IndexVaultJob::dispatchSync($vault->id);
            $vault->refresh();
        }

        $bulletsText = $avatar->bullets->pluck('content')->implode("\n");
        $profileText = $avatar->ownerProfile ? $avatar->ownerProfile->getOptimizedText() : '';
        $vaultDesc   = $vault ? $vault->description : '';

        $playerId = null;
        if ($avatar->ownerProfile) {
            $player = \App\Domains\Community\Models\Player::where('discord_id', $avatar->ownerProfile->discord_user_id)->first();
            if ($player) {
                $playerId = $player->id;
            }
        }

        $entityType    = $avatar->entityType;
        $optimizedText = '';
        $rawTerms      = [];

        if ($entityType && filled($entityType->system_prompt) && filled($avatar->content_raw)) {
            Log::info('[IndexAvatarJob] Usando ContextOptimizer pipeline', [
                'avatar_id'      => $avatar->id,
                'entity_type_id' => $entityType->id,
            ]);

            $softFields = $promptBuilder->extractSoftFields($entityType, $avatar->content_raw);

            // Avatar.name is a system-level field, not archetype-specific — always injected first
            if (filled($avatar->name)) {
                $softFields = array_merge(['Name' => $avatar->name], $softFields);
            }

            $builtPrompt = $promptBuilder->buildPrompt($entityType, $softFields);

            if (filled($builtPrompt) && filled($softFields)) {
                try {
                    $result        = $contextOptimizer->optimize($builtPrompt, $playerId);
                    $optimizedText = $result['optimized_text_en'];
                    $avatar->saveOptimizedText($optimizedText);
                    $avatar->update(['semantic_tag_query' => $result['semantic_tag_query']]);

                    // Dispatch tag normalization — corre después del Qdrant upsert de este job
                    $rawTerms = array_values(array_filter(
                        array_map('trim', preg_split('/\s*,\s*/', $result['semantic_tag_query']) ?: [])
                    ));
                } catch (\RuntimeException $e) {
                    Log::error('[IndexAvatarJob] ContextOptimizer falló', [
                        'avatar_id' => $avatar->id,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]);
                    $avatar->update([
                        'indexing_status' => IndexingStatus::Failed,
                        'index_error'     => '[ContextOptimizer] ' . $e->getMessage(),
                    ]);
                    return;
                }
            } else {
                Log::warning('[IndexAvatarJob] Sin prompt o softFields — entity_type mal configurado', [
                    'avatar_id' => $avatar->id,
                ]);
                $avatar->update([
                    'indexing_status' => IndexingStatus::Failed,
                    'index_error'     => 'entity_type sin system_prompt válido o sin soft fields extraíbles',
                ]);
                return;
            }
        } else {
            Log::info('[IndexAvatarJob] Usando StyleOptimizer pipeline (legacy)', [
                'avatar_id' => $avatar->id,
            ]);

            // Pipeline original con texto de bullets + perfil + vault
            $textToOptimize = trim("Avatar:\n{$bulletsText}\n\nProfile Context:\n{$profileText}\n\nVault Context:\n{$vaultDesc}");

            if ($textToOptimize === '') {
                // Fallback adicional: usar content_raw directamente como texto semántico
                $rawValues = array_filter(
                    is_array($avatar->content_raw) ? $avatar->content_raw : [],
                    fn($v) => is_string($v) && filled($v)
                );
                $textToOptimize = implode('. ', $rawValues);
            }

            if ($textToOptimize === '') {
                Log::warning("IndexAvatarJob: avatar {$avatar->id} sin texto para indexar.");
                $avatar->update([
                    'indexing_status' => IndexingStatus::Failed,
                    'index_error'     => 'Sin texto para indexar: bullets, perfil y vault vacíos',
                ]);
                return;
            }

            try {
                $optimizedText = $styleOptimizer->optimize($textToOptimize, $playerId);
            } catch (\RuntimeException $e) {
                Log::error('IndexAvatarJob: optimizer failed.', [
                    'avatar_id' => $avatar->id,
                    'error'     => $e->getMessage(),
                ]);
                $avatar->update([
                    'indexing_status' => IndexingStatus::Failed,
                    'index_error'     => '[StyleOptimizer] ' . $e->getMessage(),
                ]);
                return;
            }

            $avatar->saveOptimizedText($optimizedText);
        }

        $model = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');
        $vector = $gateway->embed($model, $optimizedText);

        if (empty($vector)) {
            Log::error("IndexAvatarJob: embedding failed for avatar {$avatar->id}.");
            $avatar->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'Embedding vacío — fallo en EmbeddingGateway',
            ]);
            return;
        }

        // Vector guardado en SQL — el upsert a Qdrant ocurre en FinalizeAvatarIndexJob
        // cuando todos los tags ya están normalizados.
        $avatar->update([
            'avatar_context_vector' => $vector,
            'indexing_status'       => IndexingStatus::Processing,
            'index_error'           => null,
        ]);

        Log::info('[IndexAvatarJob] Vector guardado — despachando pipeline de finalización.', [
            'avatar_id'   => $avatar->id,
            'vector_dims' => count($vector),
            'raw_terms'   => count($rawTerms),
        ]);

        if (! empty($rawTerms)) {
            NormalizeAvatarTagsJob::dispatch($avatar->id, $rawTerms, $playerId ?: null);
        } else {
            FinalizeAvatarIndexJob::dispatch($avatar->id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Avatar::find($this->avatarId)?->update([
            'indexing_status' => IndexingStatus::Failed,
            'index_error'     => '[crash] ' . $exception->getMessage(),
        ]);

        Log::error('[IndexAvatarJob] Job terminado por excepción no controlada', [
            'avatar_id' => $this->avatarId,
            'error'     => $exception->getMessage(),
        ]);
    }
}
