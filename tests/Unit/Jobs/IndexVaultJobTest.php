<?php

namespace Tests\Unit\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Narrative\Models\Vault;
use App\Jobs\IndexVaultJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class IndexVaultJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserts_vault_to_matchmaking_hub()
    {
        $vault = Vault::create([
            'id' => 'vault-123',
            'name' => 'Neo-Tokyo',
            'status' => 'active',
            'description' => 'Cyberpunk city.',
            'world_notes' => ['Rainy', 'Neon lights'],
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->andReturn([0.1, 0.2, 0.3]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')->once()->andReturn(true);

        $job = new IndexVaultJob('vault-123');
        $job->handle($gateway, $qdrant);

        $vault->refresh();
        $this->assertEquals([0.1, 0.2, 0.3], $vault->vault_setting_vector);
        $this->assertTrue($vault->is_hub_indexed);
        $this->assertNotNull($vault->vault_hub_qdrant_id);
        $this->assertEquals("Neo-Tokyo\nCyberpunk city.\nRainy\nNeon lights", $vault->getOptimizedText());
    }

    public function test_skips_when_vault_not_found()
    {
        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->never();

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')->never();

        $job = new IndexVaultJob('nonexistent-vault');
        $job->handle($gateway, $qdrant);

        $this->assertTrue(true); // Ensure no exceptions
    }

    public function test_reuses_existing_qdrant_id()
    {
        $vault = Vault::create([
            'id' => 'vault-123',
            'name' => 'Neo-Tokyo',
            'status' => 'active',
            'description' => 'Cyberpunk city.',
            'vault_hub_qdrant_id' => 'existing-uuid',
        ]);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->andReturn([0.1, 0.2, 0.3]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')->with('existing-uuid', Mockery::any(), Mockery::any())->once()->andReturn(true);

        $job = new IndexVaultJob('vault-123');
        $job->handle($gateway, $qdrant);

        $vault->refresh();
        $this->assertEquals('existing-uuid', $vault->vault_hub_qdrant_id);
    }
}
