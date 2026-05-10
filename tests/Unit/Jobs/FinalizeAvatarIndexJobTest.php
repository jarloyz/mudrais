<?php

namespace Tests\Unit\Jobs;

use App\Application\Services\QdrantService;
use App\Domains\Narrative\Models\Avatar;
use App\Enums\IndexingStatus;
use App\Jobs\FinalizeAvatarIndexJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FinalizeAvatarIndexJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserts_to_qdrant_and_sets_indexed_status(): void
    {
        $avatar = Avatar::create([
            'id'                   => 'avatar-finalize',
            'name'                 => 'Finalize Me',
            'avatar_context_vector' => [0.1, 0.2, 0.3],
            'indexing_status'      => IndexingStatus::Processing,
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')
            ->once()
            ->withArgs(function ($qdrantId, $vectors, $payload) use ($avatar) {
                return $payload['avatar_id'] === $avatar->id
                    && isset($payload['tags'])
                    && $vectors['avatar_context'] === [0.1, 0.2, 0.3];
            })
            ->andReturn(true);

        $job = new FinalizeAvatarIndexJob('avatar-finalize');
        $job->handle($qdrant);

        $avatar->refresh();
        $this->assertEquals(IndexingStatus::Indexed, $avatar->indexing_status);
        $this->assertTrue((bool) $avatar->is_hub_indexed);
        $this->assertNotNull($avatar->avatar_hub_qdrant_id);
        $this->assertNull($avatar->index_error);
    }

    public function test_sets_failed_when_upsert_returns_false(): void
    {
        $avatar = Avatar::create([
            'id'                   => 'avatar-false',
            'name'                 => 'False Avatar',
            'avatar_context_vector' => [0.5, 0.6],
            'indexing_status'      => IndexingStatus::Processing,
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')->once()->andReturn(false);

        $job = new FinalizeAvatarIndexJob('avatar-false');
        $job->handle($qdrant);

        $avatar->refresh();
        $this->assertEquals(IndexingStatus::Failed, $avatar->indexing_status);
        $this->assertStringContainsString('false', $avatar->index_error);
    }

    public function test_sets_failed_when_qdrant_throws(): void
    {
        $avatar = Avatar::create([
            'id'                   => 'avatar-throw',
            'name'                 => 'Throw Avatar',
            'avatar_context_vector' => [0.1, 0.2],
            'indexing_status'      => IndexingStatus::Processing,
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')
            ->once()
            ->andThrow(new \RuntimeException('Qdrant connection refused'));

        $job = new FinalizeAvatarIndexJob('avatar-throw');
        $job->handle($qdrant);

        $avatar->refresh();
        $this->assertEquals(IndexingStatus::Failed, $avatar->indexing_status);
        $this->assertStringContainsString('Qdrant connection refused', $avatar->index_error);
    }

    public function test_sets_failed_when_vector_missing(): void
    {
        $avatar = Avatar::create([
            'id'             => 'avatar-novector',
            'name'           => 'No Vector',
            'indexing_status' => IndexingStatus::Processing,
            // avatar_context_vector NOT set
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldNotReceive('upsertHubPoint');

        $job = new FinalizeAvatarIndexJob('avatar-novector');
        $job->handle($qdrant);

        $avatar->refresh();
        $this->assertEquals(IndexingStatus::Failed, $avatar->indexing_status);
        $this->assertStringContainsString('Vector no disponible', $avatar->index_error);
    }

    public function test_does_nothing_when_avatar_not_found(): void
    {
        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldNotReceive('upsertHubPoint');

        $job = new FinalizeAvatarIndexJob('avatar-nonexistent');
        $job->handle($qdrant);

        $this->assertTrue(true); // sin excepción es suficiente
    }

    public function test_includes_semantic_tags_in_payload(): void
    {
        $avatar = Avatar::create([
            'id'                   => 'avatar-with-tags',
            'name'                 => 'Tagged Avatar',
            'avatar_context_vector' => [0.7, 0.8],
            'indexing_status'      => IndexingStatus::Processing,
        ]);

        $tag = \App\Models\CanonicalTag::create([
            'slug'      => 'epic_fantasy',
            'name'      => 'Epic Fantasy',
            'is_active' => true,
        ]);

        $avatar->tags()->attach($tag->id, [
            'tag_context'   => 'semantic',
            'original_text' => 'epic fantasy',
        ]);

        $capturedPayload = null;

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('upsertHubPoint')
            ->once()
            ->withArgs(function ($qdrantId, $vectors, $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
                return true;
            })
            ->andReturn(true);

        $job = new FinalizeAvatarIndexJob('avatar-with-tags');
        $job->handle($qdrant);

        $this->assertContains($tag->id, $capturedPayload['tags']);
    }
}
