<?php

namespace Tests\Unit\Application\Services;

use App\Application\Services\QdrantService;
use App\Models\QdrantLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class QdrantServiceLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_qdrant_service_logs_successful_operations(): void
    {
        Http::fake([
            '*' => Http::response(['result' => ['status' => 'ok']], 200)
        ]);

        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        $service = new QdrantService($logger);

        $service->ensureProfilesCollection(2048);
        $service->deletePlayerVector('test-uuid');

        $this->assertDatabaseHas('qdrant_logs', [
            'collection_name' => 'players_profiles',
            'operation' => 'deletePlayerVector',
            'status' => 'success',
        ]);
    }

    public function test_qdrant_service_logs_failed_operations(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Not found'], 404)
        ]);

        $entries = [];
        $logger = new ArrayStructuredLogger($entries);
        $service = new QdrantService($logger);

        $service->deletePlayerVector('test-uuid');

        $this->assertDatabaseHas('qdrant_logs', [
            'collection_name' => 'players_profiles',
            'operation' => 'deletePlayerVector',
            'status' => 'error',
        ]);
    }

    public function test_search_with_filters_logs_top_result_and_score(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => [
                    ['payload' => ['content' => 'El dragón vive en las montañas'], 'score' => 0.92],
                    ['payload' => ['content' => 'El castillo cayó en llamas'], 'score' => 0.71],
                ],
            ], 200),
        ]);

        $logger = new ArrayStructuredLogger();
        $service = new QdrantService($logger);

        $service->searchWithFilters(
            queryVector: [0.1, 0.2, 0.3],
            vaultId: 'vault-test-1',
            currentIntimacy: 0,
            limit: 3,
            queryText: '¿Dónde vive el dragón?',
        );

        $this->assertDatabaseHas('qdrant_logs', [
            'operation'  => 'searchWithFilters',
            'status'     => 'success',
            'query_text' => '¿Dónde vive el dragón?',
            'top_result' => 'El dragón vive en las montañas',
        ]);

        $log = QdrantLog::where('operation', 'searchWithFilters')->first();
        $this->assertNotNull($log);
        $this->assertEqualsWithDelta(0.92, $log->top_score, 0.001);
    }

    public function test_search_with_filters_logs_null_when_no_query_text_provided(): void
    {
        Http::fake([
            '*' => Http::response(['result' => []], 200),
        ]);

        $logger = new ArrayStructuredLogger();
        $service = new QdrantService($logger);

        $service->searchWithFilters(
            queryVector: [0.1, 0.2],
            vaultId: 'vault-test-2',
        );

        $this->assertDatabaseHas('qdrant_logs', [
            'operation'  => 'searchWithFilters',
            'status'     => 'success',
            'query_text' => null,
            'top_result' => null,
            'top_score'  => null,
        ]);
    }
}
