<?php

namespace Tests\Feature\Repositories;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Models\Player;
use App\Repositories\PlayerMatchmakingRepository;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerMatchmakingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_compatible_players(): void
    {
        $player = Player::create([
            'discord_id' => '123',
            'username'   => 'CompatiblePlayer',
        ]);

        $mockEmbedding = $this->createMock(EmbeddingGateway::class);
        $mockEmbedding->method('embed')->willReturn(array_fill(0, 1024, 0.5));

        $mockQdrant = $this->createMock(QdrantService::class);
        $mockQdrant->expects($this->once())
            ->method('searchProfiles')
            ->with(
                $this->anything(),
                $this->callback(fn ($f) =>
                    ($f['experience_level'] ?? null) === 3 &&
                    in_array('gore', $f['red_lines_to_avoid'] ?? [], true)
                ),
                10,
            )
            ->willReturn([['id' => $player->id, 'score' => 0.95]]);

        $mockResolver = $this->createMock(UserAiSettingsResolver::class);
        $mockResolver->method('resolveAgentModel')->willReturn('test-embedding-model');

        $repository = new PlayerMatchmakingRepository($mockQdrant, $mockEmbedding, $mockResolver);

        $results = $repository->findCompatiblePlayers('Looking for a mystery lover', [
            'experience_level'   => 3,
            'red_lines_to_avoid' => ['gore'],
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals($player->id, $results[0]['player']->id);
        $this->assertEquals(0.95, $results[0]['score']);
    }

    public function test_returns_empty_when_embedding_fails(): void
    {
        $mockEmbedding = $this->createMock(EmbeddingGateway::class);
        $mockEmbedding->method('embed')->willReturn([]);

        $mockQdrant   = $this->createMock(QdrantService::class);
        $mockQdrant->expects($this->never())->method('searchProfiles');

        $mockResolver = $this->createMock(UserAiSettingsResolver::class);
        $mockResolver->method('resolveAgentModel')->willReturn('test-model');

        $repository = new PlayerMatchmakingRepository($mockQdrant, $mockEmbedding, $mockResolver);

        $this->assertEmpty($repository->findCompatiblePlayers('query'));
    }
}
