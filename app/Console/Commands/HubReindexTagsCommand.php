<?php

namespace App\Console\Commands;

use App\Application\Services\TagNormalizerService;
use App\Models\CanonicalTag;
use Illuminate\Console\Command;

class HubReindexTagsCommand extends Command
{
    protected $signature = 'hub:reindex-tags {--force : Proceder sin confirmación}';

    protected $description = 'Re-indexa toda la taxonomía de tags en Qdrant usando el texto enriquecido (Nombre + Descripción).';

    public function handle(TagNormalizerService $service): int
    {
        $tags = CanonicalTag::where('is_active', true)->get();

        if ($tags->isEmpty()) {
            $this->info('No hay tags activos para re-indexar.');
            return self::SUCCESS;
        }

        $missingDescriptions = $tags->filter(fn($t) => empty($t->description))->count();

        if ($missingDescriptions > 0) {
            $this->warn("Atención: {$missingDescriptions} tags no tienen descripción y se indexarán solo por nombre.");
        }

        if (!$this->option('force') && !$this->confirm("¿Estás seguro de que quieres re-indexar {$tags->count()} tags? Esto actualizará sus vectores en Qdrant.")) {
            return self::FAILURE;
        }

        $this->info("Iniciando re-indexación de {$tags->count()} tags...");
        $bar = $this->output->createProgressBar($tags->count());
        $bar->start();

        foreach ($tags as $tag) {
            try {
                $service->indexExistingTag($tag);
            } catch (\Throwable $e) {
                $this->error("\nError indexando tag {$tag->slug}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Re-indexación de taxonomía completada.');

        return self::SUCCESS;
    }
}
