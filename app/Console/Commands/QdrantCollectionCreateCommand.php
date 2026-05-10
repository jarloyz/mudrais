<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class QdrantCollectionCreateCommand extends Command
{
    protected $signature = 'qdrant:setup
                            {--fresh : Elimina las colecciones existentes antes de crearlas}
                            {--drop-legacy : Elimina colecciones deprecadas (players_profiles, mudrais_profiles, etc)}
                            {--host= : Qdrant host (default: QDRANT_HOST)}
                            {--port= : Qdrant port (default: QDRANT_PORT)}';

    protected $description = 'Crea las colecciones de Qdrant requeridas por MUDRAIS y elimina las deprecadas.';

    private string $baseUrl;
    private array  $headers;

    /** Colecciones requeridas con su configuración */
    private array $collections = [
        'historia_lore' => [
            'description' => 'Lore entries del vault (RAG narrativo)',
            'vectors'     => [
                'size'     => 2048,
                'distance' => 'Cosine',
            ]
        ],
        'taxonomy_tags' => [
            'description' => 'Normalización semántica de tags canónicos',
            'vectors'     => [
                'size'     => 2048,
                'distance' => 'Cosine',
            ]
        ],
        'matchmaking_hub' => [
            'description' => 'Hub unificado de matchmaking (LFG/P2P)',
            'vectors'     => [
                'player_style'    => ['size' => 2048, 'distance' => 'Cosine'],
                'vault_setting'   => ['size' => 2048, 'distance' => 'Cosine'],
                'avatar_context'  => ['size' => 2048, 'distance' => 'Cosine'],
                'activity_vibe'   => ['size' => 2048, 'distance' => 'Cosine'],
                'archetype_style' => ['size' => 2048, 'distance' => 'Cosine'],
            ]
        ],
    ];

    /** Colecciones antiguas que deben eliminarse */
    private array $deprecated = ['knowledge_chunks', 'players_profiles', 'mudrais_profiles', 'player_matchmaking'];

    public function handle(): int
    {
        $host = $this->option('host') ?: config('services.qdrant.host', 'localhost');
        $port = $this->option('port') ?: config('services.qdrant.port', '6333');
        $apiKey = config('services.qdrant.api_key', '');

        $this->baseUrl = "http://{$host}:{$port}";
        $this->headers = array_filter([
            'Content-Type' => 'application/json',
            'api-key'      => $apiKey ?: null,
        ]);

        $this->info("Qdrant: {$this->baseUrl}");
        $this->newLine();

        // 1. Eliminar colecciones deprecadas
        if ($this->option('drop-legacy')) {
            $this->deleteDeprecated();
        }

        // 2. Eliminar colecciones actuales si --fresh
        if ($this->option('fresh')) {
            $this->warn('--fresh: eliminando colecciones actuales...');
            foreach (array_keys($this->collections) as $name) {
                $this->deleteCollection($name);
            }
            $this->newLine();
        }

        // 3. Crear colecciones requeridas
        $ok = true;
        foreach ($this->collections as $name => $meta) {
            $ok = $this->ensureCollection($name, $meta['description'], $meta['vectors']) && $ok;
        }

        $this->newLine();
        if ($ok) {
            $this->info('✓ Setup de Qdrant completado.');
        } else {
            $this->error('✗ Algunos pasos fallaron. Revisa los mensajes anteriores.');
        }

        return $ok ? static::SUCCESS : static::FAILURE;
    }

    private function deleteDeprecated(): void
    {
        foreach ($this->deprecated as $name) {
            $exists = $this->collectionExists($name);
            if (!$exists) {
                $this->line("  [skip] '{$name}' no existe.");
                continue;
            }

            $this->warn("  [deprecado] Eliminando colección '{$name}'...");
            $this->deleteCollection($name);
        }
    }

    private function ensureCollection(string $name, string $description, array $vectorsConfig): bool
    {
        $this->info("  Colección '{$name}' — {$description}");

        if ($this->collectionExists($name)) {
            $this->line("    → ya existe, sin cambios.");
            return true;
        }

        $payload = [
            'vectors' => $vectorsConfig,
            'hnsw_config' => [
                'm'            => 16,
                'ef_construct' => 100,
            ],
            'optimizers_config' => [
                'indexing_threshold' => 20000,
            ],
        ];

        $response = Http::withHeaders($this->headers)->put(
            "{$this->baseUrl}/collections/{$name}",
            $payload
        );

        if ($response->successful()) {
            $this->info("    → creada correctamente.");
            return true;
        }

        $this->error("    → ERROR {$response->status()}: {$response->body()}");
        return false;
    }

    private function deleteCollection(string $name): void
    {
        $response = Http::withHeaders($this->headers)
            ->delete("{$this->baseUrl}/collections/{$name}");

        if ($response->successful()) {
            $this->line("    → '{$name}' eliminada.");
        } else {
            $this->warn("    → no se pudo eliminar '{$name}': {$response->status()}");
        }
    }

    private function collectionExists(string $name): bool
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->get("{$this->baseUrl}/collections/{$name}");
            return $response->status() === 200;
        } catch (\Throwable) {
            return false;
        }
    }
}
