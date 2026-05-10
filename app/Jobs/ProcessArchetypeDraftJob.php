<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use App\Domains\Matchmaking\Models\ArchetypeDraft;
use App\Infrastructure\Ai\Agents\ArchetypeOptimizerAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessArchetypeDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(
        public string $draftId
    ) {}

    public function handle(
        ArchetypeOptimizerAgent $optimizerAgent,
        EmbeddingGateway $embeddingGateway,
        QdrantService $qdrantService,
        \App\Support\UserAiSettingsResolver $settingsResolver
    ): void {
        $draft = ArchetypeDraft::find($this->draftId);

        if (! $draft) {
            Log::warning('[ProcessArchetypeDraftJob] Draft no encontrado.', ['draft_id' => $this->draftId]);
            return;
        }

        if ($draft->status !== ArchetypeDraftStatus::PENDING) {
            Log::info('[ProcessArchetypeDraftJob] Draft no está PENDING, saltando.', [
                'draft_id' => $this->draftId,
                'status' => $draft->status->value
            ]);
            return;
        }

        $draft->update(['status' => ArchetypeDraftStatus::PROCESSING->value]);

        try {
            $result = $optimizerAgent->optimize($draft->input_name, $draft->input_text);
            $slug = Str::slug($result['name_en']);

            $model = $settingsResolver->resolveAgentModel(null, 'embedding');
            if (empty($model)) {
                $model = config('services.ai.embedding_model', 'nomic-ai/nomic-embed-text-v1.5');
            }

            Log::debug('[ProcessArchetypeDraftJob] Generando vector de estilo (optimized_text_en).', ['draft_id' => $draft->id]);
            $styleVector = $embeddingGateway->embed($model, $result['optimized_text_en']);

            Log::debug('[ProcessArchetypeDraftJob] Generando vector taxonómico (semantic_tag_query).', [
                'draft_id'   => $draft->id,
                'tag_query'  => mb_substr($result['semantic_tag_query'], 0, 80),
            ]);
            $tagVector = $embeddingGateway->embed($model, $result['semantic_tag_query']);

            $qdrantResults = $qdrantService->searchTaxonomyTags($tagVector, 5, 0.82);

            $suggestedTags = array_map(fn($r) => [
                'source'           => 'qdrant',
                'canonical_tag_id' => (string) $r['payload']['canonical_tag_id'],
                'slug'             => $r['payload']['slug'],
                'name'             => $r['payload']['name'],
                'score'            => round($r['score'], 4),
            ], $qdrantResults);

            $draft->update([
                'name_es'            => $result['name_es'],
                'name_en'            => $result['name_en'],
                'slug'               => $slug,
                'optimized_text_en'  => $result['optimized_text_en'],
                'semantic_tag_query' => $result['semantic_tag_query'],
                'style_vector'       => $styleVector,
                'suggested_tags'     => $suggestedTags,
                'status'             => ArchetypeDraftStatus::NEEDS_REVIEW->value,
                'processing_error'   => null,
            ]);

            Log::info('[ProcessArchetypeDraftJob] Draft procesado exitosamente.', ['draft_id' => $draft->id]);
        } catch (\Throwable $e) {
            $draft->update([
                'status'           => ArchetypeDraftStatus::ERROR->value,
                'processing_error' => $e->getMessage(),
            ]);
            Log::error('[ProcessArchetypeDraftJob] Error al procesar draft.', [
                'draft_id' => $draft->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
