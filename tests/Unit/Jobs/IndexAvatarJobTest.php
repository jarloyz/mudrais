<?php

namespace Tests\Unit\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use App\Enums\IndexingStatus;
use App\Infrastructure\Ai\Agents\ContextOptimizerAgent;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use App\Jobs\FinalizeAvatarIndexJob;
use App\Jobs\IndexAvatarJob;
use App\Jobs\IndexVaultJob;
use App\Jobs\NormalizeAvatarTagsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class IndexAvatarJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_vector_and_sets_processing_status(): void
    {
        Bus::fake([NormalizeAvatarTagsJob::class, FinalizeAvatarIndexJob::class]);

        $vault = Vault::create([
            'id'                   => 'vault-123',
            'name'                 => 'Test Vault',
            'vault_setting_vector' => [0.1, 0.2],
        ]);

        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player    = Player::create(['discord_id' => 'discord123', 'username' => 'tester']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'       => $player->id,
            'discord_user_id' => 'discord123',
            'archetype_id'    => $archetype->id,
            'red_lines'       => [],
            'positive_prefs'  => [],
            'yellow_lines'    => [],
        ]);
        $profile->saveOptimizedText('Optimized profile text.');

        $avatar = Avatar::create([
            'id'               => 'avatar-123',
            'name'             => 'John Doe',
            'vault_id'         => 'vault-123',
            'owner_profile_id' => $profile->id,
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->andReturn([0.5, 0.6]);

        $optimizer = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')
            ->withArgs(fn($text) => str_contains($text, 'Optimized profile text.'))
            ->andReturn('Fused and optimized text');

        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $job = new IndexAvatarJob('avatar-123');
        $job->handle($gateway, $optimizer, $contextOptimizer, $promptBuilder);

        $avatar->refresh();
        $this->assertEquals([0.5, 0.6], $avatar->avatar_context_vector);
        $this->assertEquals(IndexingStatus::Processing, $avatar->indexing_status);
        $this->assertFalse((bool) $avatar->is_hub_indexed);
        $this->assertEquals('Fused and optimized text', $avatar->getOptimizedText());

        // Sin semantic_tag_query → FinalizeAvatarIndexJob directo
        Bus::assertDispatched(FinalizeAvatarIndexJob::class, fn($j) => $j->avatarId === 'avatar-123');
        Bus::assertNotDispatched(NormalizeAvatarTagsJob::class);
    }

    public function test_triggers_vault_indexing_if_not_indexed(): void
    {
        Bus::fake([IndexVaultJob::class, FinalizeAvatarIndexJob::class]);

        Vault::create([
            'id'   => 'vault-123',
            'name' => 'Test Vault',
            // vault_setting_vector vacío — debe disparar IndexVaultJob
        ]);

        $avatar = Avatar::create([
            'id'       => 'avatar-123',
            'name'     => 'John Doe',
            'vault_id' => 'vault-123',
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->andReturn([0.5, 0.6]);

        $optimizer = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')->andReturn('Optimized');

        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $job = new IndexAvatarJob('avatar-123');
        $job->handle($gateway, $optimizer, $contextOptimizer, $promptBuilder);

        Bus::assertDispatchedSync(IndexVaultJob::class, fn($j) => $j->vaultId === 'vault-123');
    }

    public function test_marks_failed_when_optimizer_throws(): void
    {
        $player    = Player::create(['discord_id' => 'discord-fail', 'username' => 'failer']);
        $archetype = \App\Domains\Matchmaking\Models\Archetype::create(['name' => 'FA', 'qdrant_vector_name' => 'fa']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'       => $player->id,
            'discord_user_id' => 'discord-fail',
            'archetype_id'    => $archetype->id,
            'red_lines'       => [],
            'positive_prefs'  => [],
            'yellow_lines'    => [],
        ]);
        $avatar = Avatar::create([
            'id'               => 'avatar-fail',
            'name'             => 'Fail Avatar',
            'owner_profile_id' => $profile->id,
        ]);

        $gateway          = Mockery::mock(EmbeddingGateway::class);
        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $optimizer = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')->andThrow(new \RuntimeException('LLM timeout'));

        $job = new IndexAvatarJob('avatar-fail');
        $job->handle($gateway, $optimizer, $contextOptimizer, $promptBuilder);

        $avatar->refresh();
        $this->assertEquals(IndexingStatus::Failed, $avatar->indexing_status);
        $this->assertStringContainsString('LLM timeout', $avatar->index_error);
        $this->assertFalse((bool) $avatar->is_hub_indexed);
    }

    public function test_marks_failed_when_embedding_returns_empty(): void
    {
        Bus::fake([FinalizeAvatarIndexJob::class]);

        $avatar = Avatar::create(['id' => 'avatar-embed-fail', 'name' => 'Embed Fail']);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->andReturn([]);

        $optimizer = Mockery::mock(StyleOptimizerAgent::class);
        $optimizer->shouldReceive('optimize')->andReturn('some text');

        $contextOptimizer = Mockery::mock(ContextOptimizerAgent::class);
        $promptBuilder    = Mockery::mock(\App\Domains\Matchmaking\Services\EntityTypePromptBuilderService::class);

        $job = new IndexAvatarJob('avatar-embed-fail');
        $job->handle($gateway, $optimizer, $contextOptimizer, $promptBuilder);

        $avatar->refresh();
        $this->assertEquals(IndexingStatus::Failed, $avatar->indexing_status);
        Bus::assertNotDispatched(FinalizeAvatarIndexJob::class);
    }
}
