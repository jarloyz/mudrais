<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Models\LoreEntry;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexLoreEntryJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 2;

    public function __construct(public readonly string $loreEntryId)
    {
        $this->onQueue('index');
    }

    public function handle(EmbeddingGateway $gateway, QdrantService $qdrant): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $entry = LoreEntry::find($this->loreEntryId);

        if (! $entry) {
            Log::warning('IndexLoreEntryJob: LoreEntry no encontrada.', ['lore_entry_id' => $this->loreEntryId]);
            return;
        }

        $vector = $gateway->embed('text-embedding-3-small', $entry->content);

        if (empty($vector)) {
            Log::error('IndexLoreEntryJob: embedding fallido.', ['lore_entry_id' => $this->loreEntryId]);
            return;
        }

        try {
            $success = $qdrant->syncLoreEntry($entry, $vector);
        } catch (\Throwable $e) {
            Log::error('[IndexLoreEntryJob] Excepción durante syncLoreEntry.', [
                'lore_entry_id' => $this->loreEntryId,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);
            return;
        }

        Log::info('IndexLoreEntryJob: ' . ($success ? 'sincronizado' : 'fallo Qdrant') . '.', [
            'lore_entry_id' => $this->loreEntryId,
        ]);
    }
}
