<?php

namespace Tests\Unit\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Enums\ActivityStatus;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use App\Enums\IndexingStatus;
use App\Infrastructure\Ai\Agents\ContextOptimizerAgent;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use App\Jobs\IndexActivityJob;
use App\Jobs\IndexAvatarJob;
use App\Jobs\IndexVaultJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class IndexActivityJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_recycles_vectors_without_re_embedding(): void
    {
        $vault = Vault::create([
            'id'                   => 'vault-123',
            'name'                 => 'Vault 123',
            'vault_setting_vector' => [0.1, 0.1],
        ]);

        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player    = Player::create(['discord_id' => 'discord123', 'username' => 'tester']);

        $profile = PlayerArchetypeProfile::create([
            'player_id'           => $player->id,
            'discord_user_id'     => 'discord123',
            'archetype_id'        => $archetype->id,
            'red_lines'           => [],
            'positive_prefs'      => [],
            'yellow_lines'        => [],
            'player_style_vector' => [0.2, 0.2],
        ]);

        $avatar = Avatar::create([
            'id'                    => 'avatar-123',
            'name'                  => 'John',
            'vault_id'              => 'vault-123',
            'owner_profile_id'      => $profile->id,
            'avatar_context_vector' => [0.3, 0.3],
        ]);

        $activity = Activity::create([
            'id'                => 'activity-123',
            'vault_id'          => 'vault-123',
            'creator_profile_id' => $profile->id,
            'requires_avatar'   => true,
            'title'             => 'Heist',
            'objective'         => 'Steal',
            'status'            => ActivityStatus::RECRUITING,
        ]);

        DB::table('activity_members')->insert([
            'id'                          => \Illuminate\Support\Str::uuid()->toString(),
            'activity_id'                 => 'activity-123',
            'avatar_id'                   => 'avatar-123',
            'player_archetype_profile_id' => $profile->id,
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->once()->andReturn([0.4, 0.4]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchTaxonomyTags')->andReturn([]);
        $qdrant->shouldReceive('upsertHubPoint')->with(
            Mockery::any(),
            Mockery::on(function ($vectors) {
                return $vectors['player_style'] === [0.2, 0.2]
                    && $vectors['vault_setting'] === [0.1, 0.1]
                    && $vectors['avatar_context'] === [0.3, 0.3]
                    && $vectors['activity_vibe'] === [0.4, 0.4];
            }),
            Mockery::on(function ($payload) {
                return $payload['entity_type'] === 'activity' && $payload['status'] === 'ready';
            })
        )->once()->andReturn(true);

        $optimizer        = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')->andReturn('Optimized vibe');

        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $job = new IndexActivityJob('activity-123');
        $job->handle($gateway, $qdrant, $optimizer, $contextOptimizer, $promptBuilder);

        $activity->refresh();
        $this->assertTrue($activity->is_hub_indexed);
        $this->assertEquals(IndexingStatus::Indexed, $activity->indexing_status);
        $this->assertNotNull($activity->activity_hub_qdrant_id);
    }

    public function test_super_point_has_3_named_vectors_without_avatar(): void
    {
        $vault = Vault::create([
            'id'                   => 'vault-124',
            'name'                 => 'Vault 124',
            'vault_setting_vector' => [0.1, 0.1],
        ]);

        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player    = Player::create(['discord_id' => 'discord124', 'username' => 'tester']);

        $profile = PlayerArchetypeProfile::create([
            'player_id'           => $player->id,
            'discord_user_id'     => 'discord124',
            'archetype_id'        => $archetype->id,
            'red_lines'           => [],
            'positive_prefs'      => [],
            'yellow_lines'        => [],
            'player_style_vector' => [0.2, 0.2],
        ]);

        $activity = Activity::create([
            'id'                => 'activity-124',
            'vault_id'          => 'vault-124',
            'creator_profile_id' => $profile->id,
            'requires_avatar'   => false,
            'title'             => 'Casual Game',
            'status'            => ActivityStatus::RECRUITING,
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->once()->andReturn([0.4, 0.4]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchTaxonomyTags')->andReturn([]);
        $qdrant->shouldReceive('upsertHubPoint')->with(
            Mockery::any(),
            Mockery::on(function ($vectors) {
                return isset($vectors['player_style'])
                    && isset($vectors['vault_setting'])
                    && isset($vectors['activity_vibe'])
                    && ! isset($vectors['avatar_context']);
            }),
            Mockery::any()
        )->once()->andReturn(true);

        $optimizer        = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')->andReturn('Optimized vibe');

        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $job = new IndexActivityJob('activity-124');
        $job->handle($gateway, $qdrant, $optimizer, $contextOptimizer, $promptBuilder);

        $activity->refresh();
        $this->assertTrue($activity->is_hub_indexed);
    }

    public function test_cascade_triggers_missing_prerequisites(): void
    {
        Bus::fake([IndexVaultJob::class, IndexAvatarJob::class]);

        $vault = Vault::create([
            'id'   => 'vault-125',
            'name' => 'Vault 125',
            // Missing vault_setting_vector
        ]);

        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player    = Player::create(['discord_id' => 'discord125', 'username' => 'tester']);

        $profile = PlayerArchetypeProfile::create([
            'player_id'           => $player->id,
            'discord_user_id'     => 'discord125',
            'archetype_id'        => $archetype->id,
            'red_lines'           => [],
            'positive_prefs'      => [],
            'yellow_lines'        => [],
            'player_style_vector' => [0.2, 0.2],
        ]);

        $avatar = Avatar::create([
            'id'               => 'avatar-125',
            'name'             => 'John',
            'vault_id'         => 'vault-125',
            'owner_profile_id' => $profile->id,
            // Missing avatar_context_vector
        ]);

        $activity = Activity::create([
            'id'                => 'activity-125',
            'vault_id'          => 'vault-125',
            'creator_profile_id' => $profile->id,
            'requires_avatar'   => true,
            'title'             => 'Heist',
        ]);

        DB::table('activity_members')->insert([
            'id'                          => \Illuminate\Support\Str::uuid()->toString(),
            'activity_id'                 => 'activity-125',
            'avatar_id'                   => 'avatar-125',
            'player_archetype_profile_id' => $profile->id,
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->andReturn([0.4, 0.4]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchTaxonomyTags')->andReturn([]);
        $qdrant->shouldReceive('upsertHubPoint')->andReturn(true);

        $optimizer        = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')->andReturn('Optimized vibe');

        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $job = new IndexActivityJob('activity-125');
        $job->handle($gateway, $qdrant, $optimizer, $contextOptimizer, $promptBuilder);

        Bus::assertDispatchedSync(IndexVaultJob::class);
        Bus::assertDispatchedSync(IndexAvatarJob::class);
    }

    public function test_aborts_if_player_vector_missing(): void
    {
        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player    = Player::create(['discord_id' => 'discord126', 'username' => 'tester']);

        $profile = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id' => 'discord126',
            'archetype_id'   => $archetype->id,
            'red_lines'      => [],
            'positive_prefs' => [],
            'yellow_lines'   => [],
            // Missing player_style_vector
        ]);

        $vault = Vault::create([
            'id'   => 'vault-test-dummy',
            'name' => 'Dummy Vault',
        ]);

        $activity = Activity::create([
            'id'                => 'activity-126',
            'vault_id'          => $vault->id,
            'creator_profile_id' => $profile->id,
            'requires_avatar'   => false,
            'title'             => 'Heist',
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->never();

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')->never();

        $optimizer        = Mockery::mock(StyleOptimizerAgent::class);
        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $job = new IndexActivityJob('activity-126');
        $job->handle($gateway, $qdrant, $optimizer, $contextOptimizer, $promptBuilder);

        $activity->refresh();
        $this->assertFalse($activity->is_hub_indexed);
        $this->assertEquals(IndexingStatus::Failed, $activity->indexing_status);
        $this->assertNotNull($activity->index_error);
    }
}
