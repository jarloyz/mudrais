<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use App\Domains\Narrative\Models\Vault;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Activity;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Jobs\IndexVaultJob;
use App\Jobs\IndexAvatarJob;
use App\Jobs\IndexActivityJob;
use App\Jobs\IndexArchetypeJob;
use App\Jobs\IndexPlayerStyleJob;

class HubReindexCommand extends Command
{
    protected $signature = 'hub:reindex
                            {--entity=all : Entities to reindex (vault|avatar|activity|archetype|profile|all)}
                            {--limit=50 : Limit of entities to process (ignored if --force)}
                            {--force : Ignore is_hub_indexed checks and reindex ALL matching entities}
                            {--sync : Run the jobs synchronously one by one instead of queuing them}';

    protected $description = 'Dispatch jobs to reindex entities into matchmaking_hub (use --force to reindex all)';

    public function handle(): int
    {
        $entity = $this->option('entity');
        $limit = (int) $this->option('limit');
        $force = $this->option('force');
        $sync = $this->option('sync');

        $this->info("Starting reindex for: {$entity} " . ($force ? "(FORCE ALL)" : "(limit: {$limit})") . ($sync ? " [SYNC MODE]" : ""));

        $jobs = [];

        if (in_array($entity, ['archetype', 'all'])) {
            $query = Archetype::query();
            if (!$force) {
                $query->where('is_hub_indexed', false);
            }
            $models = $force ? $query->get() : $query->limit($limit)->get();
            $this->info("Found {$models->count()} archetypes to reindex.");
            foreach ($models as $m) {
                if ($force) {
                    $m->updateQuietly(['is_hub_indexed' => false]);
                }
                $jobs[] = new IndexArchetypeJob($m->id);
            }
        }

        if (in_array($entity, ['profile', 'all'])) {
            $query = PlayerArchetypeProfile::query();
            if (!$force) {
                $query->where('is_vectorized', false)->orWhereNull('qdrant_id');
            }
            $models = $force ? $query->get() : $query->limit($limit)->get();
            $this->info("Found {$models->count()} profiles to reindex.");
            foreach ($models as $m) {
                if ($force) {
                    $m->updateQuietly(['is_vectorized' => false, 'player_style_vector' => null]);
                }
                $jobs[] = new IndexPlayerStyleJob($m->id);
            }
        }

        if (in_array($entity, ['vault', 'all'])) {
            $query = Vault::query();
            if (!$force) {
                $query->where('is_hub_indexed', false);
            }
            $models = $force ? $query->get() : $query->limit($limit)->get();
            $this->info("Found {$models->count()} vaults to reindex.");
            foreach ($models as $m) {
                if ($force) {
                    $m->updateQuietly(['is_hub_indexed' => false, 'vault_setting_vector' => null]);
                }
                $jobs[] = new IndexVaultJob($m->id);
            }
        }

        if (in_array($entity, ['avatar', 'all'])) {
            $query = Avatar::query();
            if (!$force) {
                $query->where('is_hub_indexed', false);
            }
            $models = $force ? $query->get() : $query->limit($limit)->get();
            $this->info("Found {$models->count()} avatars to reindex.");
            foreach ($models as $m) {
                if ($force) {
                    $m->updateQuietly(['is_hub_indexed' => false, 'avatar_context_vector' => null]);
                }
                $jobs[] = new IndexAvatarJob($m->id);
            }
        }

        if (in_array($entity, ['activity', 'all'])) {
            $query = Activity::query();
            if (!$force) {
                $query->where('is_hub_indexed', false);
            }
            $models = $force ? $query->get() : $query->limit($limit)->get();
            $this->info("Found {$models->count()} activities to reindex.");
            foreach ($models as $m) {
                if ($force) {
                    $m->updateQuietly(['is_hub_indexed' => false]);
                }
                $jobs[] = new IndexActivityJob($m->id);
            }
        }

        if (empty($jobs)) {
            $this->info("No entities found to reindex.");
            return static::SUCCESS;
        }

        if ($sync) {
            $this->info("Running " . count($jobs) . " jobs synchronously one by one...");
            $bar = $this->output->createProgressBar(count($jobs));
            $bar->start();
            foreach ($jobs as $job) {
                try {
                    dispatch_sync($job);
                } catch (\Throwable $e) {
                    $this->error("\nError executing job: " . get_class($job) . " - " . $e->getMessage());
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine(2);
            $this->info("Synchronous execution completed.");
        } else {
            $this->info("Dispatching batch of " . count($jobs) . " jobs to the queue...");
            try {
                Bus::batch($jobs)->name('Hub Reindex')->dispatch();
                $this->info("Batch dispatched successfully. Make sure queue worker is running.");
            } catch (\Throwable $e) {
                $this->error("Failed to dispatch batch: " . $e->getMessage());
                $this->warn("Dispatching jobs individually to the queue as fallback...");
                foreach ($jobs as $job) {
                    dispatch($job);
                }
                $this->info("Individual jobs dispatched to the queue.");
            }
        }

        return static::SUCCESS;
    }
}
