<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Narrative\Models\Vault;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IndexVaultJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly string $vaultId
    ) {
        $this->onQueue('index');
    }

    public function handle(EmbeddingGateway $gateway, QdrantService $qdrant): void
    {
        $vault = Vault::with('memberships')->find($this->vaultId);

        if (! $vault) {
            return;
        }

        // Build text: name + description + world_notes
        $worldNotesArray = is_array($vault->world_notes) ? $vault->world_notes : [];
        $worldNotesLines = [];
        foreach ($worldNotesArray as $note) {
            if (is_array($note)) {
                $parts = [];
                foreach (['title', 'content'] as $key) {
                    $val = $note[$key] ?? '';
                    if (is_array($val)) {
                        $val = implode(' ', array_filter($val, fn($v) => is_scalar($v)));
                    }
                    if (filled($val)) {
                        $parts[] = (string)$val;
                    }
                }
                if (! empty($parts)) {
                    $worldNotesLines[] = implode(': ', $parts);
                }
            } elseif (is_scalar($note)) {
                $worldNotesLines[] = (string) $note;
            }
        }
        $worldNotes = implode("\n", array_filter($worldNotesLines));

        $text = trim("{$vault->name}\n{$vault->description}\n{$worldNotes}");

        if ($text === '') {
            Log::warning("IndexVaultJob: vault {$vault->id} empty text.");
            return;
        }

        // We use a default embedding model here, or we can use UserAiSettingsResolver.
        // As per the specification, EmbeddingGateway::embed requires a model name.
        // Assuming default model for now, e.g. "nvidia/llama-nemotron-embed-vl-1b-v2:free"
        $model = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');

        $vector = $gateway->embed($model, $text);

        if (empty($vector)) {
            Log::error("IndexVaultJob: embedding failed for vault {$vault->id}.");
            return;
        }

        $guildIds = [$vault->id]; // The Vault ID itself acts as the Guild ID
        $tags = DB::table('taggables')
            ->where('taggable_type', Vault::class)
            ->where('taggable_id', $vault->id)
            ->pluck('canonical_tag_id')
            ->all();

        $qdrantId = $vault->vault_hub_qdrant_id ?: (string) \Illuminate\Support\Str::uuid();

        $payload = [
            'entity_type'  => 'vault',
            'guild_ids'    => $guildIds,
            'archetype_id' => $vault->primaryArchetype()?->id,
            'is_public'    => (bool)$vault->is_public,
            'tags'         => $tags,
        ];

        try {
            $success = $qdrant->upsertHubPoint($qdrantId, ['vault_setting' => $vector], $payload);
        } catch (\Throwable $e) {
            Log::error('[IndexVaultJob] Excepción durante upsert a Qdrant.', [
                'vault_id' => $vault->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return;
        }

        if ($success) {
            $vault->update([
                'vault_setting_vector' => $vector,
                'vault_hub_qdrant_id'  => $qdrantId,
                'is_hub_indexed'       => true,
            ]);
            $vault->saveOptimizedText($text);
            Log::info("IndexVaultJob: vault {$vault->id} indexed in matchmaking_hub.");
        } else {
            Log::error("IndexVaultJob: Qdrant sync failed for vault {$vault->id}.");
        }
    }
}
