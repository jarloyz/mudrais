<?php

namespace Tests\Unit\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Jobs\IndexPlayerStyleJob;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class IndexPlayerStyleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserts_to_matchmaking_hub_not_legacy_collections()
    {
        $archetype = \App\Domains\Matchmaking\Models\Archetype::create([
            'name' => 'Test',
            'qdrant_vector_name' => 'test'
        ]);

        $player = Player::create(['discord_id' => 'discord123', 'username' => 'tester']);
        $profile = PlayerArchetypeProfile::create([
            'player_id' => $player->id,
            'discord_user_id' => 'discord123',
            'archetype_id' => $archetype->id,
            'red_lines' => ['gore'],
            'positive_prefs' => ['magic'],
            'yellow_lines' => ['spiders'],
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->andReturn([0.1, 0.2, 0.3]);

        $qdrant = Mockery::mock(QdrantService::class);
        // It should call upsertHubPoint or at least mock pass
        $qdrant->shouldReceive('upsertHubPoint')->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn($vectors) => isset($vectors['player_style'])),
                Mockery::on(function ($payload) use ($profile) {
                    return $payload['player_profile_id'] === $profile->id && !isset($payload['player_id']);
                })
            )
            ->andReturn(true);

        $resolver = Mockery::mock(UserAiSettingsResolver::class);
        $resolver->shouldReceive('resolveAgentModel')->andReturn('model-name');

        $optimizer = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')->andReturn('optimized text');

        $job = new IndexPlayerStyleJob($profile->id);
        $job->handle($gateway, $qdrant, $resolver, $optimizer);

        $profile->refresh();
        $this->assertEquals([0.1, 0.2, 0.3], $profile->player_style_vector);
        $this->assertTrue($profile->is_vectorized);
    }
}
