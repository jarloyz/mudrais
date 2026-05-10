<?php

namespace Tests\Unit\Jobs;

use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Enums\ActivityStatus;
use App\Domains\Narrative\Models\Activity;
use App\Jobs\SyncActivityHubStatusJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class SyncActivityHubStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_status_in_qdrant_hub()
    {
        $vault = \App\Domains\Narrative\Models\Vault::create(['id' => 'vault-sync', 'name' => 'sync vault']);
        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'sync', 'qdrant_vector_name' => 'sync']);
        $player = \App\Domains\Community\Models\Player::create(['discord_id' => 'discord-sync', 'username' => 'sync']);
        $profile = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id' => 'discord-sync',
            'archetype_id'   => $archetype->id,
            'red_lines'      => [],
            'positive_prefs' => [],
            'yellow_lines'   => [],
        ]);

        $activity = Activity::create([
            'id' => 'act-sync-1',
            'vault_id' => $vault->id,
            'creator_profile_id' => $profile->id,
            'is_hub_indexed' => true,
            'activity_hub_qdrant_id' => 'qdrant-act-1',
            'status' => ActivityStatus::ACTIVE,
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('updateHubPayload')
            ->with('qdrant-act-1', ['status' => ActivityStatus::ACTIVE->value])
            ->once()
            ->andReturn(true);

        $job = new SyncActivityHubStatusJob('act-sync-1');
        $job->handle($qdrant);

        // Assertion is handled by Mockery verifying updateHubPayload was called correctly.
        $this->assertTrue(true);
    }

    public function test_skips_if_activity_not_hub_indexed()
    {
        $vault = \App\Domains\Narrative\Models\Vault::create(['id' => 'vault-sync-2', 'name' => 'sync vault']);
        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'sync', 'qdrant_vector_name' => 'sync']);
        $player2 = \App\Domains\Community\Models\Player::create(['discord_id' => 'discord-sync-2', 'username' => 'sync']);
        $profile = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::create([
            'player_id'      => $player2->id,
            'discord_user_id' => 'discord-sync-2',
            'archetype_id'   => $archetype->id,
            'red_lines'      => [],
            'positive_prefs' => [],
            'yellow_lines'   => [],
        ]);

        $activity = Activity::create([
            'id' => 'act-sync-2',
            'vault_id' => $vault->id,
            'creator_profile_id' => $profile->id,
            'is_hub_indexed' => false,
            'activity_hub_qdrant_id' => null,
            'status' => ActivityStatus::ACTIVE,
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('updateHubPayload')->never();

        $job = new SyncActivityHubStatusJob('act-sync-2');
        $job->handle($qdrant);

        $this->assertTrue(true);
    }
}
