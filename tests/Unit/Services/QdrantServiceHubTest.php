<?php

namespace Tests\Unit\Services;

use App\Application\Contracts\StructuredLogger;
use App\Application\Services\QdrantService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Mockery;

class QdrantServiceHubTest extends TestCase
{
    private QdrantService $qdrant;
    private $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loggerMock = Mockery::mock(StructuredLogger::class);
        $this->loggerMock->shouldIgnoreMissing();

        $this->qdrant = new QdrantService($this->loggerMock);
    }

    public function test_ensure_matchmaking_hub_collection()
    {
        Http::fake([
            '*/collections/matchmaking_hub' => Http::sequence()
                ->pushStatus(404)
                ->pushStatus(200),
        ]);

        $this->qdrant->ensureMatchmakingHubCollection(2048);

        Http::assertSent(function ($request) {
            if ($request->method() === 'PUT' && str_contains($request->url(), 'matchmaking_hub')) {
                $data = $request->data();
                return isset($data['vectors']['player_style']['size']) && $data['vectors']['player_style']['size'] === 2048;
            }
            return true; // the GET request
        });
    }

    public function test_upsert_hub_point()
    {
        Http::fake([
            '*/collections/matchmaking_hub/points?wait=true' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = $this->qdrant->upsertHubPoint('uuid-123', ['player_style' => [0.1, 0.2]], ['entity_type' => 'player_profile']);

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            if ($request->method() === 'PUT') {
                $data = $request->data();
                return str_contains($request->url(), 'wait=true')
                    && $data['points'][0]['id'] === 'uuid-123'
                    && $data['points'][0]['vector']['player_style'] === [0.1, 0.2]
                    && $data['points'][0]['payload']['entity_type'] === 'player_profile';
            }
            return false;
        });
    }

    public function test_update_hub_payload()
    {
        Http::fake([
            '*/collections/matchmaking_hub/points/payload' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = $this->qdrant->updateHubPayload('uuid-123', ['status' => 2]);

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->method() === 'POST'
                && $data['points'][0] === 'uuid-123'
                && $data['payload']['status'] === 2;
        });
    }

    public function test_delete_hub_point()
    {
        Http::fake([
            '*/collections/matchmaking_hub/points/delete' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = $this->qdrant->deleteHubPoint('uuid-123');

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST' && $request->data()['points'][0] === 'uuid-123';
        });
    }

    public function test_search_hub()
    {
        Http::fake([
            '*/collections/matchmaking_hub/points/search' => Http::response(['result' => [['id' => 'uuid-123', 'score' => 0.99]]], 200),
        ]);

        $result = $this->qdrant->searchHub('player_style', [0.1, 0.2], [['key' => 'entity_type', 'match' => ['value' => 'activity']]]);

        $this->assertCount(1, $result);
        $this->assertEquals('uuid-123', $result[0]['id']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->method() === 'POST'
                && $data['vector']['name'] === 'player_style'
                && $data['vector']['vector'] === [0.1, 0.2]
                && $data['filter']['must'][0]['key'] === 'entity_type';
        });
    }

    public function test_get_hub_vector()
    {
        Http::fake([
            '*/collections/matchmaking_hub/points/uuid-123?with_vectors=true' => Http::response(['result' => ['vectors' => ['activity_vibe' => [0.5, 0.6]]]], 200),
        ]);

        $result = $this->qdrant->getHubVector('uuid-123', 'activity_vibe');

        $this->assertEquals([0.5, 0.6], $result);
    }
}
