<?php

namespace Tests\Feature\Api\V2;

use App\Models\Player;
use App\Repositories\PlayerMatchmakingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchmakingControllerTest extends TestCase
{
    use RefreshDatabase;

    // --- Test 1: Devuelve la forma correcta de respuesta ---

    public function test_search_returns_formatted_player_list(): void
    {
        $player  = Player::factory()->create([
            'username'   => 'dragonqueen',
            'discord_id' => '999888777',
            'elo'        => 1450,
        ]);

        $mockRepo = $this->createMock(PlayerMatchmakingRepository::class);
        $mockRepo->expects($this->once())
            ->method('findCompatiblePlayers')
            ->with('narrador experto', ['experience_level' => 5], null, 10)
            ->willReturn([
                ['player' => $player, 'score' => 0.9512],
            ]);

        $this->app->instance(PlayerMatchmakingRepository::class, $mockRepo);

        $response = $this->postJson('/api/v2/matchmaking/search', [
            'query'   => 'narrador experto',
            'filters' => ['experience_level' => 5],
            'limit'   => 10,
        ]);

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.username', 'dragonqueen')
            ->assertJsonPath('0.discord_id', '999888777')
            ->assertJsonPath('0.elo', 1450)
            ->assertJsonPath('0.score', 0.9512);
    }

    // --- Test 2: Valida que `query` es requerido ---

    public function test_search_requires_query(): void
    {
        $response = $this->postJson('/api/v2/matchmaking/search', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    }

    // --- Test 3: Devuelve array vacío cuando no hay resultados ---

    public function test_search_returns_empty_array_when_no_results(): void
    {
        $mockRepo = $this->createMock(PlayerMatchmakingRepository::class);
        $mockRepo->method('findCompatiblePlayers')->willReturn([]);
        $this->app->instance(PlayerMatchmakingRepository::class, $mockRepo);

        $response = $this->postJson('/api/v2/matchmaking/search', [
            'query' => 'jugador muy específico que no existe',
        ]);

        $response->assertOk()->assertExactJson([]);
    }

    // --- Test 4: El parámetro `limit` se pasa correctamente al repositorio ---

    public function test_search_passes_limit_to_repository(): void
    {
        $mockRepo = $this->createMock(PlayerMatchmakingRepository::class);
        $mockRepo->expects($this->once())
            ->method('findCompatiblePlayers')
            ->with($this->anything(), $this->anything(), $this->anything(), 5)
            ->willReturn([]);

        $this->app->instance(PlayerMatchmakingRepository::class, $mockRepo);

        $this->postJson('/api/v2/matchmaking/search', [
            'query' => 'búsqueda limitada',
            'limit' => 5,
        ])->assertOk();
    }
}
