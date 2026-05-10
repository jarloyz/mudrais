<?php

namespace Tests\Unit\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Jobs\IndexLoreEntryJob;
use App\Models\LoreEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndexLoreEntryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexes_lore_entry_in_qdrant(): void
    {
        $entry = LoreEntry::factory()->create([
            'content'  => 'Historia secreta de la facción.',
            'metadata' => ['type' => 'canon'],
        ]);

        $vector  = array_fill(0, 8, 0.1);
        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')
            ->once()
            ->andReturn($vector);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('syncLoreEntry')
            ->once()
            ->with(Mockery::on(fn($e) => $e->id === $entry->id), $vector)
            ->andReturn(true);

        $job = new IndexLoreEntryJob($entry->id);
        $job->handle($gateway, $qdrant);
    }

    public function test_skips_missing_lore_entry_gracefully(): void
    {
        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldNotReceive('embed');

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldNotReceive('syncLoreEntry');

        $job = new IndexLoreEntryJob(999999);
        $job->handle($gateway, $qdrant);

        $this->assertTrue(true);
    }

    public function test_skips_qdrant_when_embedding_fails(): void
    {
        $entry = LoreEntry::factory()->create(['content' => 'Texto de prueba.']);

        $gateway = Mockery::mock(EmbeddingGateway::class);
        $gateway->shouldReceive('embed')->once()->andReturn([]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldNotReceive('syncLoreEntry');

        $job = new IndexLoreEntryJob($entry->id);
        $job->handle($gateway, $qdrant);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
