<?php

namespace App\Console\Commands;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Models\CanonicalTag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReindexTagsCommand extends Command
{
    protected $signature = 'tags:reindex
                            {--fresh : Re-indexa todos los tags aunque ya estén indexados}
                            {--chunk=50 : Tamaño de lote}';

    protected $description = 'Indexa canonical_tags en la colección taxonomy_tags de Qdrant.';

    public function handle(EmbeddingGateway $gateway, QdrantService $qdrant): int
    {
        $model = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');

        $query = CanonicalTag::where('is_active', true);

        if (! $this->option('fresh')) {
            // Solo los que no tienen punto en Qdrant todavía (descripción vacía = nunca indexados)
            // Usamos is_active como proxy; en una iteración futura se puede añadir qdrant_indexed column.
        }

        $total = $query->count();
        $chunk = (int) $this->option('chunk');

        $this->info("Tags a indexar: {$total} (chunk={$chunk})");
        $this->newLine();

        $bar     = $this->output->createProgressBar($total);
        $ok      = 0;
        $failed  = 0;
        $collectionReady = false;

        $query->orderBy('id')->chunk($chunk, function ($tags) use ($gateway, $qdrant, $model, $bar, &$ok, &$failed, &$collectionReady) {
            foreach ($tags as $tag) {
                $slugWords = str_replace('_', ' ', $tag->slug);
                $parts     = array_filter([$slugWords, $tag->name, $tag->description]);
                $text      = implode('. ', array_unique($parts));

                try {
                    $vector = $gateway->embed($model, $text);

                    if (empty($vector)) {
                        Log::warning('[ReindexTagsCommand] Embedding vacío', ['tag_id' => $tag->id, 'slug' => $tag->slug]);
                        $failed++;
                        $bar->advance();
                        continue;
                    }

                    // Ensure collection with correct dimensions from first real vector
                    if (! $collectionReady) {
                        $qdrant->ensureTaxonomyCollection(count($vector));
                        $collectionReady = true;
                    }

                    $inserted = $qdrant->insertTaxonomyTag($tag->id, $vector, [
                        'canonical_tag_id' => $tag->id,
                        'slug'             => $tag->slug,
                        'name'             => $tag->name,
                    ]);

                    if (! $inserted) {
                        Log::warning('[ReindexTagsCommand] Qdrant rechazó la inserción', ['tag_id' => $tag->id, 'slug' => $tag->slug]);
                        $failed++;
                        $bar->advance();
                        continue;
                    }

                    Log::debug('[ReindexTagsCommand] Tag indexado', ['slug' => $tag->slug]);
                    $ok++;
                } catch (\Throwable $e) {
                    Log::error('[ReindexTagsCommand] Error al indexar tag', [
                        'tag_id'  => $tag->id,
                        'slug'    => $tag->slug,
                        'error'   => $e->getMessage(),
                    ]);
                    $failed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Indexados: {$ok}");

        if ($failed > 0) {
            $this->warn("✗ Fallidos: {$failed}");
        }

        return $failed === 0 ? static::SUCCESS : static::FAILURE;
    }
}
