<?php

namespace Tests\Unit\Application\Services;

use App\Application\Services\QdrantService;
use App\Models\LoreEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class QdrantLineageTest extends TestCase
{
    use RefreshDatabase;

    private QdrantService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QdrantService(new ArrayStructuredLogger());
    }

    // ─── buildVersionEndFilter ─────────────────────────────────────────────

    public function test_build_version_end_filter_returns_two_conditions(): void
    {
        $filter = $this->service->buildVersionEndFilter(3);

        $this->assertCount(2, $filter);
    }

    public function test_build_version_end_filter_first_condition_is_null_check(): void
    {
        $filter = $this->service->buildVersionEndFilter(3);

        $this->assertArrayHasKey('is_null', $filter[0]);
        $this->assertSame('lineage.version_end', $filter[0]['is_null']['key']);
    }

    public function test_build_version_end_filter_second_condition_is_range_gte(): void
    {
        $filter = $this->service->buildVersionEndFilter(5);

        $this->assertArrayHasKey('key', $filter[1]);
        $this->assertSame('lineage.version_end', $filter[1]['key']);
        $this->assertSame(5, $filter[1]['range']['gte']);
    }

    public function test_build_version_end_filter_uses_provided_version(): void
    {
        $filter = $this->service->buildVersionEndFilter(7);

        $this->assertSame(7, $filter[1]['range']['gte']);
    }

    // ─── syncLoreEntry — payload de linaje ────────────────────────────────

    public function test_sync_lore_entry_includes_lineage_in_payload(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $entry = new LoreEntry([
            'vault_id' => 'vault_test',
            'entity_id' => 'entity_1',
            'content' => 'Kira es una mercenaria élite.',
            'metadata' => [],
            'lineage_id' => 'char_kira',
            'version_start' => 2,
            'version_end' => null,
        ]);
        $entry->id = 999;

        $result = $this->service->syncLoreEntry($entry, array_fill(0, 8, 0.1));

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $lineage = $body['points'][0]['payload']['lineage'] ?? null;

            return $lineage !== null
                && $lineage['lineage_id'] === 'char_kira'
                && $lineage['version_start'] === 2
                && $lineage['version_end'] === null;
        });
    }

    public function test_sync_lore_entry_includes_version_end_when_set(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $entry = new LoreEntry([
            'vault_id' => 'vault_test',
            'entity_id' => 'entity_1',
            'content' => 'Versión antigua del personaje.',
            'metadata' => [],
            'lineage_id' => 'char_kira',
            'version_start' => 1,
            'version_end' => 3,
        ]);
        $entry->id = 1000;

        $this->service->syncLoreEntry($entry, array_fill(0, 8, 0.1));

        Http::assertSent(function ($request) {
            $lineage = $request->data()['points'][0]['payload']['lineage'] ?? null;
            return $lineage !== null && $lineage['version_end'] === 3;
        });
    }

    // ─── searchWithLineage — construcción del filtro ───────────────────────

    public function test_search_with_lineage_sends_correct_filter_structure(): void
    {
        Http::fake([
            '*' => Http::response(['result' => [
                ['payload' => ['content' => 'Resultado de lore']],
            ]], 200),
        ]);

        $results = $this->service->searchWithLineage(
            queryVector: array_fill(0, 8, 0.5),
            vaultId: 'vault_x',
            lineageId: 'char_kira',
            version: 3,
            currentIntimacy: 50,
            limit: 2,
        );

        $this->assertSame(['Resultado de lore'], $results);

        Http::assertSent(function ($request) {
            $filter = $request->data()['filter'] ?? [];
            $must = $filter['must'] ?? [];

            // Debe haber filtros: vault_id, lineage_id, version_start, intimacy_min
            $keys = array_column($must, 'key');
            $matchValues = array_map(
                static fn (array $c): mixed => $c['match']['value'] ?? null,
                array_filter($must, static fn (array $c): bool => isset($c['match']))
            );

            return in_array('vault_id', $keys, true)
                && in_array('lineage.lineage_id', $keys, true)
                && in_array('lineage.version_start', $keys, true)
                && in_array('requirements.intimacy_min', $keys, true)
                && in_array('vault_x', array_values($matchValues), true)
                && in_array('char_kira', array_values($matchValues), true);
        });
    }

    public function test_search_with_lineage_applies_version_start_lte_filter(): void
    {
        Http::fake(['*' => Http::response(['result' => []], 200)]);

        $this->service->searchWithLineage(
            queryVector: array_fill(0, 4, 0.1),
            vaultId: 'vault_x',
            lineageId: 'char_x',
            version: 4,
        );

        Http::assertSent(function ($request) {
            $must = $request->data()['filter']['must'] ?? [];
            foreach ($must as $condition) {
                if (($condition['key'] ?? '') === 'lineage.version_start') {
                    return ($condition['range']['lte'] ?? null) === 4;
                }
            }
            return false;
        });
    }

    public function test_search_with_lineage_includes_should_filter_for_version_end(): void
    {
        Http::fake(['*' => Http::response(['result' => []], 200)]);

        $this->service->searchWithLineage(
            queryVector: array_fill(0, 4, 0.1),
            vaultId: 'vault_x',
            lineageId: 'char_x',
            version: 2,
        );

        Http::assertSent(function ($request) {
            $filter = $request->data()['filter'] ?? [];
            $should = $filter['should'] ?? [];
            $minShould = $filter['minimum_should'] ?? 0;

            return count($should) === 2 && $minShould === 1;
        });
    }

    public function test_search_with_lineage_returns_empty_on_qdrant_error(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $results = $this->service->searchWithLineage(
            queryVector: array_fill(0, 4, 0.1),
            vaultId: 'vault_x',
            lineageId: 'char_x',
            version: 1,
        );

        $this->assertSame([], $results);
    }

    // ─── LoreEntry — isValidAtVersion ─────────────────────────────────────

    public function test_lore_entry_is_valid_at_version_when_version_end_is_null(): void
    {
        $entry = new LoreEntry([
            'version_start' => 1,
            'version_end' => null,
        ]);

        $this->assertTrue($entry->isValidAtVersion(1));
        $this->assertTrue($entry->isValidAtVersion(10));
        $this->assertTrue($entry->isValidAtVersion(999));
    }

    public function test_lore_entry_is_valid_at_version_within_closed_range(): void
    {
        $entry = new LoreEntry([
            'version_start' => 2,
            'version_end' => 5,
        ]);

        $this->assertFalse($entry->isValidAtVersion(1)); // antes del rango
        $this->assertTrue($entry->isValidAtVersion(2));
        $this->assertTrue($entry->isValidAtVersion(4));
        $this->assertTrue($entry->isValidAtVersion(5));
        $this->assertFalse($entry->isValidAtVersion(6)); // después del rango
    }

    public function test_lore_entry_is_not_valid_before_version_start(): void
    {
        $entry = new LoreEntry([
            'version_start' => 3,
            'version_end' => null,
        ]);

        $this->assertFalse($entry->isValidAtVersion(2));
        $this->assertTrue($entry->isValidAtVersion(3));
    }
}
