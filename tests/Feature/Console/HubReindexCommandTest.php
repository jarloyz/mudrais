<?php

namespace Tests\Feature\Console;

use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use App\Jobs\IndexActivityJob;
use App\Jobs\IndexAvatarJob;
use App\Jobs\IndexVaultJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class HubReindexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reindexes_only_unindexed_entities()
    {
        Bus::fake();

        Vault::create(['id' => 'vault-1', 'name' => 'V1', 'is_hub_indexed' => false]);
        Vault::create(['id' => 'vault-2', 'name' => 'V2', 'is_hub_indexed' => true]);

        Avatar::create(['id' => 'avatar-1', 'name' => 'A1', 'vault_id' => 'vault-1', 'is_hub_indexed' => false]);

        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'test', 'qdrant_vector_name' => 'test']);
        \App\Domains\Community\Models\Player::create(['discord_id' => 'user-1', 'username' => 'test1']);

        $profile = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::create([
            'discord_user_id' => 'user-1',
            'archetype_id' => $archetype->id,
            'red_lines' => [],
            'positive_prefs' => [],
            'yellow_lines' => [],
        ]);

        Activity::create([
            'id' => 'act-1',
            'vault_id' => 'vault-1',
            'creator_profile_id' => $profile->id,
            'is_hub_indexed' => false,
            'title' => 'T1'
        ]);

        $this->artisan('hub:reindex', ['--entity' => 'all'])
             ->expectsOutput('Found 1 vaults to reindex.')
             ->expectsOutput('Found 1 avatars to reindex.')
             ->expectsOutput('Found 1 activities to reindex.')
             ->expectsOutput('Batch dispatched successfully.')
             ->assertExitCode(0);

        // Since it's batched, Bus::fake() works with batches
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 3;
        });
    }

    public function test_reindexes_specific_entity()
    {
        Bus::fake();

        Vault::create(['id' => 'vault-1', 'name' => 'V1', 'is_hub_indexed' => false]);
        Avatar::create(['id' => 'avatar-1', 'name' => 'A1', 'vault_id' => 'vault-1', 'is_hub_indexed' => false]);

        $this->artisan('hub:reindex', ['--entity' => 'vault'])
             ->expectsOutput('Found 1 vaults to reindex.')
             ->doesntExpectOutput('Found 1 avatars to reindex.')
             ->assertExitCode(0);

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 1;
        });
    }

    public function test_respects_limit()
    {
        Bus::fake();

        Vault::create(['id' => 'vault-1', 'name' => 'V1', 'is_hub_indexed' => false]);
        Vault::create(['id' => 'vault-2', 'name' => 'V2', 'is_hub_indexed' => false]);

        $this->artisan('hub:reindex', ['--entity' => 'vault', '--limit' => 1])
             ->expectsOutput('Found 1 vaults to reindex.')
             ->assertExitCode(0);
    }
}
