<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SeedMassivePlayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var Player
     */
    public $player;

    /**
     * @var array
     */
    public $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(Player $player, array $payload)
    {
        $this->player = $player;
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(EmbeddingGateway $gateway, QdrantService $qdrant): void
    {
        $model = config('historia.ai.model_catalog.embedding', 'text-embedding-3-large');
        $text = $this->payload['text'] ?? '';
        $playerId = $this->player->id;

        if (empty($text)) {
            Log::warning("SeedMassivePlayerJob: Empty text for player {$playerId}");
            return;
        }

        try {
            // Generate embedding for this specific semantic text
            $vector = $gateway->embed($model, $text);

            // Sync with Qdrant
            $success = $qdrant->syncPlayerStyleVector($playerId, $vector, $this->payload);

            if (!$success) {
                Log::error("SeedMassivePlayerJob: Qdrant sync failed for player {$playerId}.");
                $this->fail(new \Exception("Qdrant sync failed"));
                return;
            }

            // Marcar jugador como vectorizado en la base de datos
            $this->player->update(['is_vectorized' => true]);

        } catch (\Exception $e) {
            Log::error("SeedMassivePlayerJob: Embedding/Qdrant Exception for player {$playerId} - " . $e->getMessage());
            $this->fail($e);
        }
    }
}
