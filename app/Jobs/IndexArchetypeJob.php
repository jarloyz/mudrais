<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Models\Archetype;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexArchetypeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public readonly string $archetypeId
    ) {
        $this->onQueue('index');
    }

    public function handle(EmbeddingGateway $gateway, QdrantService $qdrant): void
    {
        Log::debug('[IndexArchetypeJob@handle] Inicio', ['archetype_id' => $this->archetypeId]);

        $archetype = Archetype::with('tags')->find($this->archetypeId);

        if (! $archetype) {
            Log::warning('[IndexArchetypeJob@handle] Archetype no encontrado', ['archetype_id' => $this->archetypeId]);
            return;
        }

        $tagNames = $archetype->tags->pluck('name')->implode(', ');
        $text = trim(implode("\n", array_filter([
            $archetype->name,
            $archetype->summary,
            $tagNames,
        ])));

        if ($text === '') {
            Log::warning('[IndexArchetypeJob@handle] Texto vacío, se requiere name o summary', ['archetype_id' => $this->archetypeId]);
            return;
        }

        Log::debug('[IndexArchetypeJob@handle] Texto construido', [
            'archetype_id' => $this->archetypeId,
            'text_preview' => mb_substr($text, 0, 100),
        ]);

        $model  = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');
        $vector = $gateway->embed($model, $text);

        if (empty($vector)) {
            Log::error('[IndexArchetypeJob@handle] Embedding fallido', ['archetype_id' => $this->archetypeId]);
            return;
        }

        Log::info('[IndexArchetypeJob@handle] Embedding generado', [
            'archetype_id' => $this->archetypeId,
            'dims'         => count($vector),
        ]);

        $qdrantId = $archetype->archetype_hub_qdrant_id ?: (string) Str::uuid();

        $payload = [
            'entity_type'  => 'archetype',
            'archetype_id' => $archetype->id,
            'name'         => $archetype->name,
            'slug'         => $archetype->slug,
            'tags'         => $archetype->tags->pluck('id')->all(),
        ];

        Log::info('[IndexArchetypeJob@handle] Upsert a matchmaking_hub', [
            'qdrant_id'    => $qdrantId,
            'archetype_id' => $archetype->id,
        ]);

        try {
            $success = $qdrant->upsertHubPoint($qdrantId, ['archetype_style' => $vector], $payload);
        } catch (\Throwable $e) {
            Log::error('[IndexArchetypeJob@handle] Excepción durante upsert a Qdrant.', [
                'archetype_id' => $archetype->id,
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);
            return;
        }

        if ($success) {
            $archetype->update([
                'archetype_style_vector'   => $vector,
                'archetype_hub_qdrant_id'  => $qdrantId,
                'is_hub_indexed'           => true,
            ]);
            Log::info('[IndexArchetypeJob@handle] Archetype indexado correctamente', [
                'archetype_id' => $archetype->id,
                'qdrant_id'    => $qdrantId,
            ]);
        } else {
            Log::error('[IndexArchetypeJob@handle] Fallo en upsert a Qdrant', ['archetype_id' => $archetype->id]);
        }
    }
}
