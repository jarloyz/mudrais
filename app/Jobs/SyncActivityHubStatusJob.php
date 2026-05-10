<?php

namespace App\Jobs;

use App\Application\Services\QdrantService;
use App\Domains\Narrative\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncActivityHubStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $activityId
    ) {
        $this->onQueue('sync');
    }

    public function handle(QdrantService $qdrant): void
    {
        $activity = Activity::find($this->activityId);

        if (! $activity) {
            return;
        }

        if (! $activity->is_hub_indexed || ! $activity->activity_hub_qdrant_id) {
            Log::warning("SyncActivityHubStatusJob: activity {$activity->id} is not hub indexed. Aborting.");
            return;
        }

        $statusValue = $activity->status instanceof \App\Domains\Matchmaking\Enums\ActivityStatus
            ? $activity->status->value
            : (int)$activity->status;

        $success = $qdrant->updateHubPayload($activity->activity_hub_qdrant_id, ['status' => $statusValue]);

        if ($success) {
            Log::info("SyncActivityHubStatusJob: status synced for activity {$activity->id}.");
        } else {
            Log::error("SyncActivityHubStatusJob: Qdrant sync failed for activity {$activity->id}.");
        }
    }
}
