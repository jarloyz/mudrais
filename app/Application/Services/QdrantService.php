<?php

namespace App\Application\Services;

use App\Application\Contracts\StructuredLogger;
use App\Models\LoreEntry;
use App\Models\QdrantLog;
use Illuminate\Support\Facades\Http;
use Exception;

class QdrantService
{
    private string $host;
    private string $port;
    private string $apiKey;
    private string $loreCollection;
    private string $profilesCollection;
    private string $hubCollection;
    private string $mudraisCollection = 'mudrais_profiles';

    public function __construct(private readonly StructuredLogger $logger)
    {
        $this->host               = config('services.qdrant.host', 'localhost');
        $this->port               = config('services.qdrant.port', '6333');
        $this->apiKey             = config('services.qdrant.api_key', '');
        $this->loreCollection     = config('services.qdrant.collection_name', 'historia_lore');
        $this->profilesCollection = config('services.qdrant.profiles_collection', 'players_profiles');
        $this->hubCollection      = 'matchmaking_hub';
    }

    private function logQdrant(
        float $startTime,
        string $collectionName,
        string $operation,
        ?int $matchesCount = null,
        string $status = 'success',
        ?string $queryText = null,
        ?string $topResult = null,
        ?float $topScore = null,
    ): void {
        $latency = (microtime(true) - $startTime) * 1000;
        try {
            QdrantLog::create([
                'collection_name' => $collectionName,
                'operation'       => $operation,
                'latency_ms'      => $latency,
                'matches_count'   => $matchesCount,
                'status'          => $status,
                'query_text'      => $queryText,
                'top_result'      => $topResult,
                'top_score'       => $topScore,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Qdrant: Excepción al registrar logQdrant.', ['message' => $e->getMessage()]);
        }
    }

    public function syncLoreEntry(LoreEntry $entry, array $vector): bool
    {
        $url = $this->buildUrl("/collections/{$this->loreCollection}/points");
        $startTime = microtime(true);

        $payload = [
            'points' => [
                [
                    'id' => $entry->id,
                    'vector' => $vector,
                    'payload' => [
                        'vault_id' => $entry->vault_id,
                        'entity_id' => $entry->entity_id,
                        'content' => $entry->content,
                        'tags' => $entry->metadata['tags'] ?? [],
                        'requirements' => [
                            'intimacy_min' => $entry->metadata['requirements']['intimacy_min'] ?? 0,
                            'wealth_min' => $entry->metadata['requirements']['wealth_min'] ?? 0,
                            'influence_min' => $entry->metadata['requirements']['influence_min'] ?? 0,
                            'required_quest_flag' => $entry->metadata['requirements']['required_quest_flag'] ?? null,
                        ],
                        'lineage' => [
                            'lineage_id' => $entry->lineage_id,
                            'version_start' => $entry->version_start ?? 1,
                            'version_end' => $entry->version_end,
                        ],
                    ]
                ]
            ]
        ];

        try {
            $response = Http::withHeaders($this->getHeaders())->put($url, $payload);

            if ($response->successful()) {
                $this->logQdrant($startTime, $this->loreCollection, 'syncLoreEntry', 1, 'success');
                $this->logger->info('Qdrant: LoreEntry sincronizada exitosamente.', ['id' => $entry->id]);
                return true;
            }

            $this->logQdrant($startTime, $this->loreCollection, 'syncLoreEntry', null, 'error');
            $this->logger->error('Qdrant: Error al sincronizar LoreEntry.', [
                'id' => $entry->id,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return false;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->loreCollection, 'syncLoreEntry', null, 'error');
            $this->logger->error('Qdrant: Excepción al sincronizar LoreEntry.', [
                'id' => $entry->id,
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function searchWithFilters(
        array $queryVector,
        string $vaultId,
        int $currentIntimacy = 0,
        int $limit = 3,
        ?string $queryText = null,
    ): array {
        $url = $this->buildUrl("/collections/{$this->loreCollection}/points/search");
        $startTime = microtime(true);

        $payload = [
            'vector' => $queryVector,
            'limit' => $limit,
            'with_payload' => true,
            'filter' => [
                'must' => [
                    ['key' => 'vault_id', 'match' => ['value' => $vaultId]],
                    ['key' => 'requirements.intimacy_min', 'range' => ['lte' => $currentIntimacy]]
                ]
            ]
        ];

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, $payload);

            if ($response->successful()) {
                $points = $response->json('result') ?? [];
                $topResult = isset($points[0]) ? ($points[0]['payload']['content'] ?? null) : null;
                $topScore  = isset($points[0]) ? ((float) ($points[0]['score'] ?? 0.0)) : null;
                $this->logQdrant($startTime, $this->loreCollection, 'searchWithFilters', count($points), 'success', $queryText, $topResult, $topScore);

                $results = [];
                foreach ($points as $point) {
                    $results[] = $point['payload']['content'] ?? '';
                }
                return $results;
            }

            $this->logQdrant($startTime, $this->loreCollection, 'searchWithFilters', null, 'error', $queryText);
            $this->logger->error('Qdrant: Error al buscar con filtros.', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->loreCollection, 'searchWithFilters', null, 'error', $queryText);
            $this->logger->error('Qdrant: Excepción al buscar con filtros.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    public function searchWithLineage(
        array $queryVector,
        string $vaultId,
        string $lineageId,
        int $version,
        int $currentIntimacy = 0,
        int $limit = 3,
        ?string $queryText = null,
    ): array {
        $url = $this->buildUrl("/collections/{$this->loreCollection}/points/search");
        $startTime = microtime(true);

        $payload = [
            'vector' => $queryVector,
            'limit' => $limit,
            'with_payload' => true,
            'filter' => [
                'must' => [
                    ['key' => 'vault_id', 'match' => ['value' => $vaultId]],
                    ['key' => 'lineage.lineage_id', 'match' => ['value' => $lineageId]],
                    ['key' => 'lineage.version_start', 'range' => ['lte' => $version]],
                    ['key' => 'requirements.intimacy_min', 'range' => ['lte' => $currentIntimacy]],
                ],
                'should' => $this->buildVersionEndFilter($version),
                'minimum_should' => 1,
            ],
        ];

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, $payload);

            if ($response->successful()) {
                $points = $response->json('result') ?? [];
                $topResult = isset($points[0]) ? ($points[0]['payload']['content'] ?? null) : null;
                $topScore  = isset($points[0]) ? ((float) ($points[0]['score'] ?? 0.0)) : null;
                $this->logQdrant($startTime, $this->loreCollection, 'searchWithLineage', count($points), 'success', $queryText, $topResult, $topScore);

                return array_map(
                    static fn (array $point): string => (string) ($point['payload']['content'] ?? ''),
                    $points,
                );
            }

            $this->logQdrant($startTime, $this->loreCollection, 'searchWithLineage', null, 'error', $queryText);
            $this->logger->error('Qdrant: Error en búsqueda con linaje.', [
                'lineageId' => $lineageId,
                'version' => $version,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->loreCollection, 'searchWithLineage', null, 'error', $queryText);
            $this->logger->error('Qdrant: Excepción en búsqueda con linaje.', [
                'lineageId' => $lineageId,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function buildVersionEndFilter(int $version): array
    {
        return [
            ['is_null' => ['key' => 'lineage.version_end']],
            ['key' => 'lineage.version_end', 'range' => ['gte' => $version]],
        ];
    }

    public function ensureProfilesCollection(int $dimensions = 2048): void
    {
        $url = $this->buildUrl("/collections/{$this->profilesCollection}");
        try {
            $response = Http::withHeaders($this->getHeaders())->get($url);
            if ($response->successful()) {
                $size = $response->json('result.config.params.vectors.ttrpg_profile.size');
                if ($size === $dimensions) return;
                Http::withHeaders($this->getHeaders())->delete($url);
            }
            Http::withHeaders($this->getHeaders())->put($url, [
                'vectors' => ['ttrpg_profile' => ['size' => $dimensions, 'distance' => 'Cosine']],
                'on_disk_payload' => true,
            ]);
            $this->logger->info('Qdrant: players_profiles collection created.', ['dimensions' => $dimensions, 'vector' => 'ttrpg_profile']);
        } catch (Exception $e) {
            $this->logger->error('Qdrant: could not ensure players_profiles collection.', ['message' => $e->getMessage()]);
        }
    }

    public function updatePlayerPayload(string $playerId, array $fields): bool
    {
        $url = $this->buildUrl("/collections/{$this->profilesCollection}/points/payload");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, [
                'payload' => $fields,
                'points'  => [$playerId],
            ]);

            $success = $response->successful();
            $this->logQdrant($startTime, $this->profilesCollection, 'updatePlayerPayload', null, $success ? 'success' : 'error');
            return $success;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->profilesCollection, 'updatePlayerPayload', null, 'error');
            $this->logger->error("Qdrant: error updating payload for player {$playerId}.", ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function syncPlayerStyleVector(string $playerId, array $vector, array $payload): bool
    {
        $this->ensureProfilesCollection(count($vector));
        $url = $this->buildUrl("/collections/{$this->profilesCollection}/points");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->put($url, [
                'points' => [[
                    'id'      => $playerId,
                    'vectors' => ['ttrpg_profile' => $vector],
                    'payload' => $payload,
                ]],
            ]);

            $success = $response->successful();
            $this->logQdrant($startTime, $this->profilesCollection, 'syncPlayerStyleVector', 1, $success ? 'success' : 'error');
            return $success;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->profilesCollection, 'syncPlayerStyleVector', null, 'error');
            $this->logger->error("Qdrant: error syncing style vector for player {$playerId}.", ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function deletePlayerVector(string $playerId): bool
    {
        $url = $this->buildUrl("/collections/{$this->profilesCollection}/points/delete");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, ['points' => [$playerId]]);
            $ok = $response->successful();
            $this->logQdrant($startTime, $this->profilesCollection, 'deletePlayerVector', null, $ok ? 'success' : 'error');
            return $ok;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->profilesCollection, 'deletePlayerVector', null, 'error');
            return false;
        }
    }

    public function dropCollection(string $collection): bool
    {
        $url = $this->buildUrl("/collections/{$collection}");
        try {
            return Http::withHeaders($this->getHeaders())->delete($url)->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    public function searchProfiles(array $queryVector, array $filters = [], int $limit = 10, ?string $queryText = null): array
    {
        $url = $this->buildUrl("/collections/{$this->profilesCollection}/points/search");
        $startTime = microtime(true);

        $must    = [];
        $mustNot = [];

        if (isset($filters['experience_level'])) $must[] = ['key' => 'experience_level', 'match' => ['value' => (int) $filters['experience_level']]];
        if (isset($filters['verbosity_level'])) $must[] = ['key' => 'verbosity_level', 'match' => ['value' => (int) $filters['verbosity_level']]];
        foreach ($filters['red_lines_to_avoid'] ?? [] as $tag) $mustNot[] = ['key' => 'red_lines_tags', 'match' => ['value' => (string) $tag]];
        if (isset($filters['guild_id'])) $must[] = ['key' => 'guild_ids', 'match' => ['value' => (string) $filters['guild_id']]];
        if (! empty($filters['profile_ids'])) $must[] = ['key' => 'player_profile_id', 'match' => ['any' => array_values($filters['profile_ids'])]];

        $body = [
            'vector'       => ['name' => 'ttrpg_profile', 'vector' => $queryVector],
            'limit'        => $limit,
            'with_payload' => true,
            'filter'       => array_filter(['must' => $must, 'must_not' => $mustNot]),
        ];

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, $body);
            if ($response->successful()) {
                $results = $response->json('result') ?? [];
                $topResult = isset($results[0]) ? json_encode($results[0]['payload'] ?? []) : null;
                $topScore  = isset($results[0]) ? ((float) ($results[0]['score'] ?? 0.0)) : null;
                $this->logQdrant($startTime, $this->profilesCollection, 'searchProfiles', count($results), 'success', $queryText, $topResult, $topScore);
                return $results;
            }

            $this->logQdrant($startTime, $this->profilesCollection, 'searchProfiles', null, 'error', $queryText);
            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->profilesCollection, 'searchProfiles', null, 'error', $queryText);
            return [];
        }
    }

    public function getPlayerVector(string $playerId): array
    {
        $url = $this->buildUrl("/collections/{$this->profilesCollection}/points/{$playerId}?with_vectors=true");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->get($url);
            $vector = $response->successful() ? ($response->json('result.vectors.ttrpg_profile') ?? []) : [];
            $this->logQdrant($startTime, $this->profilesCollection, 'getPlayerVector', null, $response->successful() ? 'success' : 'error');
            return $vector;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->profilesCollection, 'getPlayerVector', null, 'error');
            return [];
        }
    }

    public function searchProfilesAdvanced(array $queryVector, array $must = [], array $mustNot = [], int $limit = 20, ?string $queryText = null): array
    {
        $url = $this->buildUrl("/collections/{$this->profilesCollection}/points/search");
        $startTime = microtime(true);

        $body = [
            'vector'       => ['name' => 'ttrpg_profile', 'vector' => $queryVector],
            'limit'        => $limit,
            'with_payload' => true,
            'filter'       => array_filter(['must' => $must, 'must_not' => $mustNot]),
        ];

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, $body);
            if ($response->successful()) {
                $results = $response->json('result') ?? [];
                $topResult = isset($results[0]) ? json_encode($results[0]['payload'] ?? []) : null;
                $topScore  = isset($results[0]) ? ((float) ($results[0]['score'] ?? 0.0)) : null;
                $this->logQdrant($startTime, $this->profilesCollection, 'searchProfilesAdvanced', count($results), 'success', $queryText, $topResult, $topScore);
                return $results;
            }
            $this->logQdrant($startTime, $this->profilesCollection, 'searchProfilesAdvanced', null, 'error', $queryText);
            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->profilesCollection, 'searchProfilesAdvanced', null, 'error', $queryText);
            return [];
        }
    }

    public function ensureMudraisProfilesCollection(array $vectorDefinitions): void
    {
        $url = $this->buildUrl("/collections/{$this->mudraisCollection}");
        try {
            $response = Http::withHeaders($this->getHeaders())->get($url);
            if ($response->successful()) return;
            $vectors = [];
            foreach ($vectorDefinitions as $name => $dims) $vectors[$name] = ['size' => $dims, 'distance' => 'Cosine'];
            Http::withHeaders($this->getHeaders())->put($url, ['vectors' => $vectors, 'on_disk_payload' => true]);
        } catch (Exception $e) {}
    }

    public function upsertArchetypeProfile(string $qdrantId, string $vectorName, array $vector, array $payload): bool
    {
        $url = $this->buildUrl("/collections/{$this->mudraisCollection}/points");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->put($url, [
                'points' => [[
                    'id'      => $qdrantId,
                    'vectors' => [$vectorName => $vector],
                    'payload' => $payload,
                ]],
            ]);

            $success = $response->successful();
            $this->logQdrant($startTime, $this->mudraisCollection, 'upsertArchetypeProfile', 1, $success ? 'success' : 'error');
            return $success;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->mudraisCollection, 'upsertArchetypeProfile', null, 'error');
            return false;
        }
    }

    public function searchArchetypeProfiles(
        string $vectorName,
        array $queryVector,
        string $guildId,
        string $archetypeId,
        array $mustNotRedLines = [],
        int $limit = 10,
        ?string $queryText = null,
    ): array {
        $url = $this->buildUrl("/collections/{$this->mudraisCollection}/points/search");
        $startTime = microtime(true);

        $must = [
            ['key' => 'guild_id',     'match' => ['value' => $guildId]],
            ['key' => 'archetype_id', 'match' => ['value' => $archetypeId]],
        ];

        $mustNot = [];
        if (!empty($mustNotRedLines)) $mustNot[] = ['key' => 'red_lines', 'match' => ['any' => $mustNotRedLines]];

        $body = [
            'vector'       => ['name' => $vectorName, 'vector' => $queryVector],
            'limit'        => $limit,
            'with_payload' => true,
            'filter'       => array_filter(['must' => $must, 'must_not' => $mustNot]),
        ];

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, $body);
            if ($response->successful()) {
                $results = $response->json('result') ?? [];
                $topResult = isset($results[0]) ? json_encode($results[0]['payload'] ?? []) : null;
                $topScore  = isset($results[0]) ? ((float) ($results[0]['score'] ?? 0.0)) : null;
                $this->logQdrant($startTime, $this->mudraisCollection, 'searchArchetypeProfiles', count($results), 'success', $queryText, $topResult, $topScore);
                return $results;
            }
            $this->logQdrant($startTime, $this->mudraisCollection, 'searchArchetypeProfiles', null, 'error', $queryText);
            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->mudraisCollection, 'searchArchetypeProfiles', null, 'error', $queryText);
            return [];
        }
    }

    public function getArchetypeProfileVector(string $qdrantId, string $vectorName): array
    {
        $url = $this->buildUrl("/collections/{$this->mudraisCollection}/points/{$qdrantId}?with_vectors=true");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->get($url);
            $vector = $response->successful() ? ($response->json("result.vectors.{$vectorName}") ?? []) : [];
            $this->logQdrant($startTime, $this->mudraisCollection, 'getArchetypeProfileVector', null, $response->successful() ? 'success' : 'error');
            return $vector;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->mudraisCollection, 'getArchetypeProfileVector', null, 'error');
            return [];
        }
    }

    public function ensureTaxonomyCollection(int $dimensions = 2048): void
    {
        $url = $this->buildUrl('/collections/taxonomy_tags');
        try {
            $response = Http::withHeaders($this->getHeaders())->get($url);
            if ($response->successful()) {
                $size = $response->json('result.config.params.vectors.size');
                if ($size === $dimensions) return;
                Http::withHeaders($this->getHeaders())->delete($url);
            }
            Http::withHeaders($this->getHeaders())->put($url, [
                'vectors'         => ['size' => $dimensions, 'distance' => 'Cosine'],
                'on_disk_payload' => true,
            ]);
        } catch (Exception $e) {}
    }

    public function insertTaxonomyTag(string $tagId, array $vector, array $payload): bool
    {
        $url = $this->buildUrl('/collections/taxonomy_tags/points');
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->put($url, [
                'points' => [['id' => $tagId, 'vector' => $vector, 'payload' => $payload]],
            ]);
            $success = $response->successful();
            $this->logQdrant($startTime, 'taxonomy_tags', 'insertTaxonomyTag', 1, $success ? 'success' : 'error');
            return $success;
        } catch (Exception $e) {
            $this->logQdrant($startTime, 'taxonomy_tags', 'insertTaxonomyTag', null, 'error');
            return false;
        }
    }

    public function searchTaxonomyTags(array $vector, int $limit = 3, float $scoreThreshold = 0.0, ?string $queryText = null): array
    {
        $url = $this->buildUrl('/collections/taxonomy_tags/points/search');
        $startTime = microtime(true);

        try {
            $payload = ['vector' => $vector, 'limit' => $limit, 'with_payload' => true];
            if ($scoreThreshold > 0) $payload['score_threshold'] = $scoreThreshold;

            $response = Http::withHeaders($this->getHeaders())->post($url, $payload);
            if ($response->successful()) {
                $results = $response->json('result') ?? [];
                $topResult = isset($results[0]) ? json_encode($results[0]['payload'] ?? []) : null;
                $topScore  = isset($results[0]) ? ((float) ($results[0]['score'] ?? 0.0)) : null;
                $this->logQdrant($startTime, 'taxonomy_tags', 'searchTaxonomyTags', count($results), 'success', $queryText, $topResult, $topScore);
                return $results;
            }
            $this->logQdrant($startTime, 'taxonomy_tags', 'searchTaxonomyTags', null, 'error', $queryText);
            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, 'taxonomy_tags', 'searchTaxonomyTags', null, 'error', $queryText);
            return [];
        }
    }

    public function ensureMatchmakingHubCollection(int $dimensions = 2048): void
    {
        $url = $this->buildUrl("/collections/{$this->hubCollection}");
        try {
            $response = Http::withHeaders($this->getHeaders())->get($url);
            if ($response->successful()) {
                $size = $response->json('result.config.params.vectors.avatar_context.size');
                if ($size === $dimensions) return;
                Http::withHeaders($this->getHeaders())->delete($url);
            }
            Http::withHeaders($this->getHeaders())->put($url, [
                'vectors' => [
                    'player_style'    => ['size' => $dimensions, 'distance' => 'Cosine'],
                    'vault_setting'   => ['size' => $dimensions, 'distance' => 'Cosine'],
                    'avatar_context'  => ['size' => $dimensions, 'distance' => 'Cosine'],
                    'activity_vibe'   => ['size' => $dimensions, 'distance' => 'Cosine'],
                    'archetype_style' => ['size' => $dimensions, 'distance' => 'Cosine'],
                ],
                'on_disk_payload' => true,
            ]);
        } catch (Exception $e) {}
    }

    public function upsertHubPoint(string $qdrantId, array $vectors, array $payload): bool
    {
        $url = $this->buildUrl("/collections/{$this->hubCollection}/points") . '?wait=true';
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->put($url, [
                'points' => [['id' => $qdrantId, 'vectors' => $vectors, 'payload' => $payload]]
            ]);

            $responseBody = $response->json();
            $success = $response->successful() && ($responseBody['status'] ?? '') !== 'error';
            $this->logQdrant($startTime, $this->hubCollection, 'upsertHubPoint', 1, $success ? 'success' : 'error');
            return $success;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->hubCollection, 'upsertHubPoint', null, 'error');
            return false;
        }
    }

    public function updateHubPayload(string $qdrantId, array $fields): bool
    {
        $url = $this->buildUrl("/collections/{$this->hubCollection}/points/payload");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, [
                'points'  => [$qdrantId],
                'payload' => $fields,
            ]);
            $success = $response->successful();
            $this->logQdrant($startTime, $this->hubCollection, 'updateHubPayload', null, $success ? 'success' : 'error');
            return $success;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->hubCollection, 'updateHubPayload', null, 'error');
            return false;
        }
    }

    public function deleteHubPoint(string $qdrantId): bool
    {
        $url = $this->buildUrl("/collections/{$this->hubCollection}/points/delete");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, ['points' => [$qdrantId]]);
            $success = $response->successful();
            $this->logQdrant($startTime, $this->hubCollection, 'deleteHubPoint', null, $success ? 'success' : 'error');
            return $success;
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->hubCollection, 'deleteHubPoint', null, 'error');
            return false;
        }
    }

    public function searchHub(string $vectorName, array $queryVector, array $must = [], array $mustNot = [], int $limit = 10, ?string $queryText = null): array
    {
        $url = $this->buildUrl("/collections/{$this->hubCollection}/points/search");
        $startTime = microtime(true);

        $payload = ['vector' => ['name' => $vectorName, 'vector' => $queryVector], 'limit' => $limit, 'with_payload' => true];
        if (!empty($must)) $payload['filter']['must'] = $must;
        if (!empty($mustNot)) $payload['filter']['must_not'] = $mustNot;

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, $payload);
            if ($response->successful()) {
                $results = $response->json('result') ?? [];
                $topResult = isset($results[0]) ? json_encode($results[0]['payload'] ?? []) : null;
                $topScore  = isset($results[0]) ? ((float) ($results[0]['score'] ?? 0.0)) : null;
                $this->logQdrant($startTime, $this->hubCollection, 'searchHub', count($results), 'success', $queryText, $topResult, $topScore);
                return $results;
            }
            $this->logQdrant($startTime, $this->hubCollection, 'searchHub', null, 'error', $queryText);
            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->hubCollection, 'searchHub', null, 'error', $queryText);
            return [];
        }
    }

    public function getHubVector(string $qdrantId, string $vectorName): array
    {
        $url = $this->buildUrl("/collections/{$this->hubCollection}/points/{$qdrantId}?with_vectors=true");
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->getHeaders())->get($url);
            if ($response->successful()) {
                $data = $response->json('result');
                $this->logQdrant($startTime, $this->hubCollection, 'getHubVector', null, 'success');
                return $data['vectors'][$vectorName] ?? [];
            }
            $this->logQdrant($startTime, $this->hubCollection, 'getHubVector', null, 'error');
            return [];
        } catch (Exception $e) {
            $this->logQdrant($startTime, $this->hubCollection, 'getHubVector', null, 'error');
            return [];
        }
    }

    private function buildUrl(string $endpoint): string
    {
        return "http://{$this->host}:{$this->port}" . $endpoint;
    }

    private function getHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($this->apiKey)) $headers['api-key'] = $this->apiKey;
        return $headers;
    }
}
