<?php

namespace App\Application\Services;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\EmbeddingGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Prompts\TagNormalizerPrompt;
use App\Models\CanonicalTag;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TagNormalizerService
{
    private const THRESHOLD_GREEN  = 0.88;
    private const THRESHOLD_YELLOW = 0.58;

    private bool $taxonomyCollectionReady = false;

    public function __construct(
        private EmbeddingGateway       $embedding,
        private AiChatGateway          $ai,
        private QdrantService          $qdrant,
        private UserAiSettingsResolver $resolver,
    ) {}

    /**
     * Normalize raw text into a CanonicalTag.
     *
     *   0. SQL exact match     → return sin ninguna API call
     *   1. Embed rawText       → búsqueda semántica directa (sin LLM)
     *   2. Qdrant search       → 🟢 auto-match | 🟡 gatekeeper valida | 🔴 crear
     *   3. (solo si 🔴/🟡 NUEVO) optimizer_fast enrich → Embed(enriched) → crear tag
     *
     * Para tags existentes: 0 llamadas LLM, solo embedding + búsqueda Qdrant.
     * El LLM (optimizer_fast) solo se invoca cuando hay que crear un tag nuevo.
     */
    public function normalizeTag(string $rawText, ?string $playerId = null, ?string $archetypeId = null): ?CanonicalTag
    {
        $rawText = trim($rawText);

        if ($rawText === '') {
            return null;
        }

        $systemPrompt = $this->resolveGatekeeperPrompt($archetypeId);
        $ctx = ['raw' => $rawText, 'player_id' => $playerId, 'archetype_id' => $archetypeId];

        // ── 0. SQL exact match (sin API) ──────────────────────────────────────
        $t         = microtime(true);
        $fastMatch = $this->findByText($rawText);
        if ($fastMatch !== null) {
            Log::debug('TagNormalizerService: ⚡ text match.', array_merge($ctx, [
                'slug'   => $fastMatch->slug,
                'sql_ms' => round((microtime(true) - $t) * 1000),
            ]));
            return $fastMatch;
        }

        // ── 1. Embed rawText directamente (sin LLM) ───────────────────────────
        $embeddingModel = $this->resolver->resolveAgentModel($playerId, 'embedding');
        $t              = microtime(true);
        $rawVector      = $this->embedding->embed($embeddingModel, $rawText);

        Log::debug('TagNormalizerService: raw embedding done.', array_merge($ctx, [
            'model' => $embeddingModel,
            'ms'    => round((microtime(true) - $t) * 1000),
            'dims'  => count($rawVector),
        ]));

        if (empty($rawVector)) {
            Log::warning('TagNormalizerService: raw embedding failed.', $ctx);
            return null;
        }

        // ── 2. Qdrant search con vector del rawText ───────────────────────────
        if (! $this->taxonomyCollectionReady) {
            $this->qdrant->ensureTaxonomyCollection(count($rawVector));
            $this->taxonomyCollectionReady = true;
        }

        $t         = microtime(true);
        $matches   = $this->qdrant->searchTaxonomyTags($rawVector, limit: 3, queryText: $rawText);
        $best      = $matches[0] ?? null;
        $bestScore = (float) ($best['score'] ?? 0.0);

        Log::debug('TagNormalizerService: qdrant search done.', array_merge($ctx, [
            'ms'         => round((microtime(true) - $t) * 1000),
            'best_score' => $bestScore,
            'best_slug'  => $best['payload']['slug'] ?? null,
            'candidates' => count($matches),
        ]));

        // 🟢 Auto-match: puntuación alta, return directo sin LLM
        if ($best !== null && $bestScore >= self::THRESHOLD_GREEN) {
            $tag = CanonicalTag::find((string) $best['payload']['canonical_tag_id']);
            Log::debug('TagNormalizerService: 🟢 auto-match.', array_merge($ctx, [
                'slug'  => $tag?->slug,
                'score' => $bestScore,
            ]));
            return $tag;
        }

        // 🟡 Puntuación media: gatekeeper valida si algún candidato coincide.
        // Si confirma un match, return directo. Si dice NUEVO, caemos al path de creación.
        if ($best !== null && $bestScore >= self::THRESHOLD_YELLOW) {
            $candidates = array_map(fn ($m) => [
                'slug'  => (string) ($m['payload']['slug']  ?? ''),
                'name'  => (string) ($m['payload']['name']  ?? ''),
                'score' => round((float) ($m['score'] ?? 0), 3),
            ], $matches);

            $matched = $this->verifyWithLlm($rawText, $candidates, $playerId, $systemPrompt);
            if ($matched !== null) {
                return $matched;
            }
        }

        // 🔴 Score bajo o gatekeeper rechazó — optimizer_fast genera forma canónica
        Log::debug('TagNormalizerService: 🔴 no match — enriqueciendo con optimizer_fast.', array_merge($ctx, ['score' => $bestScore]));

        $llmData = $this->enrichWithLlm($rawText, $playerId, $systemPrompt);

        // Embed del texto enriquecido; reusar rawVector si no hay descripción
        $textToEmbed    = $this->buildRichText($llmData, $rawText);
        $enrichedVector = ($textToEmbed !== $rawText)
            ? $this->embedding->embed($embeddingModel, $textToEmbed)
            : $rawVector;

        $vectorForCreate = ! empty($enrichedVector) ? $enrichedVector : $rawVector;

        // Segunda búsqueda Qdrant con el vector enriquecido — puede encontrar tags
        // que el rawText no encontró por diferencia de vocabulario
        $enrichedMatches = $this->qdrant->searchTaxonomyTags($vectorForCreate, limit: 3, queryText: $rawText);
        $enrichedBest    = $enrichedMatches[0] ?? null;
        $enrichedScore   = (float) ($enrichedBest['score'] ?? 0.0);

        Log::debug('TagNormalizerService: segunda búsqueda (enriched).', array_merge($ctx, [
            'best_score' => $enrichedScore,
            'best_slug'  => $enrichedBest['payload']['slug'] ?? null,
        ]));

        // 🟢 Match con vector enriquecido
        if ($enrichedBest !== null && $enrichedScore >= self::THRESHOLD_GREEN) {
            $tag = CanonicalTag::find((string) $enrichedBest['payload']['canonical_tag_id']);
            Log::debug('TagNormalizerService: 🟢 auto-match (enriched).', array_merge($ctx, [
                'slug'  => $tag?->slug,
                'score' => $enrichedScore,
            ]));
            return $tag;
        }

        // 🟡 Gatekeeper con candidatos enriquecidos
        if ($enrichedBest !== null && $enrichedScore >= self::THRESHOLD_YELLOW) {
            $enrichedCandidates = array_map(fn ($m) => [
                'slug'  => (string) ($m['payload']['slug']  ?? ''),
                'name'  => (string) ($m['payload']['name']  ?? ''),
                'score' => round((float) ($m['score'] ?? 0), 3),
            ], $enrichedMatches);

            $matched = $this->verifyWithLlm($rawText, $enrichedCandidates, $playerId, $systemPrompt);
            if ($matched !== null) {
                return $matched;
            }
        }

        // 🔴 Sin match — crear con los datos ya optimizados (no se vuelve a llamar LLM)
        return $this->createFromEnrichedData($llmData, $rawText, $vectorForCreate, $playerId);
    }

    /**
     * @param list<string> $items
     * @return list<CanonicalTag>
     */
    public function normalizeBatch(array $items, ?string $playerId = null, ?string $archetypeId = null): array
    {
        $tags = [];
        foreach ($items as $item) {
            $tag = $this->normalizeTag($item, $playerId, $archetypeId);
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /**
     * Indexa (o re-indexa) un tag existente en la colección taxonomy de Qdrant.
     * Usado para backfill de tags creados antes de este pipeline.
     */
    public function indexExistingTag(CanonicalTag $tag, ?string $playerId = null): void
    {
        $embeddingModel = $this->resolver->resolveAgentModel($playerId, 'embedding');
        $textToEmbed    = $this->buildRichText([
            'slug'        => $tag->slug,
            'name'        => $tag->name,
            'description' => $tag->description,
        ], $tag->name);

        $t         = microtime(true);
        $tagVector = $this->embedding->embed($embeddingModel, $textToEmbed);
        Log::debug('TagNormalizerService: tag embedding done.', [
            'slug' => $tag->slug,
            'ms'   => round((microtime(true) - $t) * 1000),
        ]);

        if (! empty($tagVector)) {
            $this->upsertTagInQdrant($tag, $tagVector);
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Zona 🟡: el LLM decide si alguno de los candidatos coincide con rawText.
     * Solo valida — devuelve el tag si hay match, null si el gatekeeper dice NUEVO.
     * La creación del tag nuevo la maneja el caller.
     *
     * @param list<array{slug:string,name:string,score:float}> $candidates
     */
    private function verifyWithLlm(string $rawText, array $candidates, ?string $playerId, ?string $systemPrompt = null): ?CanonicalTag
    {
        $model    = $this->resolver->resolveAgentModel($playerId, 'gatekeeper');
        $provider = $this->resolver->resolveAgentProvider($playerId, 'gatekeeper');
        $options  = $provider ? ['_provider' => $provider] : [];
        if ($this->resolver->resolveAgentReasoning($playerId, 'gatekeeper')) {
            $options['reasoning'] = [
                'enabled'      => true,
                'budget_tokens' => $this->resolver->resolveAgentBudgetTokens($playerId, 'gatekeeper'),
            ];
        }
        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => TagNormalizerPrompt::getVerifyPrompt($rawText, $candidates)];
        $t        = microtime(true);
        $response = $this->ai->chat($model, $messages, 0.0, 4096, null, null, null, $options);

        $slug = trim($response['text'] ?? '');

        Log::debug('TagNormalizerService: 🟡 verify response.', [
            'raw'    => $rawText,
            'slug'   => $slug,
            'llm_ms' => round((microtime(true) - $t) * 1000),
        ]);

        if ($slug === 'NUEVO' || $slug === '') {
            return null;
        }

        $tag = CanonicalTag::where('slug', $slug)->where('is_active', true)->first();

        if ($tag === null) {
            Log::warning('TagNormalizerService: 🟡 slug desconocido devuelto por LLM.', [
                'raw'  => $rawText,
                'slug' => $slug,
            ]);
            return null;
        }

        Log::debug('TagNormalizerService: 🟡 matched.', ['raw' => $rawText, 'slug' => $tag->slug]);

        return $tag;
    }

    /**
     * Llama al LLM para obtener la forma canónica de rawText.
     *
     * @return array{slug:string,name:string,description:string}|null
     */
    private function enrichWithLlm(string $rawText, ?string $playerId, ?string $systemPrompt = null): ?array
    {
        $model    = $this->resolver->resolveAgentModel($playerId, 'optimizer_fast');
        $provider = $this->resolver->resolveAgentProvider($playerId, 'optimizer_fast');
        $options  = $provider ? ['_provider' => $provider] : [];
        if ($this->resolver->resolveAgentReasoning($playerId, 'optimizer_fast')) {
            $options['reasoning'] = [
                'enabled'      => true,
                'budget_tokens' => $this->resolver->resolveAgentBudgetTokens($playerId, 'optimizer_fast'),
            ];
        }
        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => TagNormalizerPrompt::getCreatePrompt($rawText)];
        $t        = microtime(true);
        $response = $this->ai->chat($model, $messages, 0.3, 4096, null, null, null, $options);

        $llmMs = round((microtime(true) - $t) * 1000);
        $text  = trim($response['text'] ?? '');
        $text  = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text  = preg_replace('/```\s*$/i', '', $text) ?? $text;
        $data  = json_decode(trim($text), true);

        Log::debug('TagNormalizerService: LLM enrich.', [
            'raw'    => $rawText,
            'llm_ms' => $llmMs,
            'slug'   => $data['slug'] ?? null,
        ]);

        if (! is_array($data) || empty($data['slug'])) {
            Log::warning('TagNormalizerService: LLM enrich failed — fallback a rawText.', [
                'raw' => $rawText,
            ]);
            return null;
        }

        return [
            'slug'        => (string) $data['slug'],
            'name'        => (string) ($data['name']        ?? $data['slug']),
            'description' => (string) ($data['description'] ?? ''),
        ];
    }

    /**
     * Persiste el tag en DB e inserta en Qdrant reutilizando el vector precomputado.
     * Si el tag ya existe pero no tiene descripción, se le asigna la generada por el LLM.
     *
     * @param array{slug:string,name:string,description:string}|null $llmData
     * @param list<float> $vector
     */
    private function createFromEnrichedData(?array $llmData, string $rawText, array $vector, ?string $playerId): ?CanonicalTag
    {
        if ($llmData === null) {
            Log::error('TagNormalizerService: no hay datos LLM para crear el tag.', ['raw' => $rawText]);
            return null;
        }

        $slug = Str::slug($llmData['slug'], '_');
        $t    = microtime(true);

        // Intentamos recuperar si ya existe para no duplicar por slug
        $tag = CanonicalTag::where('slug', $slug)->first();

        if ($tag) {
            // Si existe pero le falta la descripción, la enriquecemos ahora
            if (empty($tag->description) && !empty($llmData['description'])) {
                $tag->update(['description' => $llmData['description']]);
                Log::debug('TagNormalizerService: tag existente enriquecido con descripción.', ['slug' => $slug]);
            }
        } else {
            $tag = CanonicalTag::create([
                'slug'        => $slug,
                'name'        => $llmData['name'],
                'description' => $llmData['description'],
                'is_active'   => true,
            ]);
        }

        Log::debug('TagNormalizerService: 🔴 DB upsert done.', [
            'slug'   => $tag->slug,
            'sql_ms' => round((microtime(true) - $t) * 1000),
        ]);

        $this->upsertTagInQdrant($tag, $vector);

        Log::info('TagNormalizerService: 🔴 tag created or updated and indexed.', ['slug' => $tag->slug]);

        return $tag;
    }

    /**
     * @param list<float> $vector
     */
    private function upsertTagInQdrant(CanonicalTag $tag, array $vector): void
    {
        $t = microtime(true);
        $this->qdrant->ensureTaxonomyCollection(count($vector));
        $this->qdrant->insertTaxonomyTag($tag->id, $vector, [
            'canonical_tag_id' => $tag->id,
            'slug'             => $tag->slug,
            'name'             => $tag->name,
        ]);
        Log::debug('TagNormalizerService: qdrant upsert done.', [
            'slug' => $tag->slug,
            'ms'   => round((microtime(true) - $t) * 1000),
        ]);
    }

    /**
     * Texto para embedding: Combina el nombre con la descripción técnica (texto optimizado).
     * Esto asegura que la búsqueda semántica tenga un "ancla" en el nombre pero use el concepto profundo.
     *
     * @param array{slug:string,name:string,description:string}|null $data
     */
    private function buildRichText(?array $data, string $fallback): string
    {
        if ($data === null) {
            return $fallback;
        }

        $name        = $data['name']        ?? '';
        $description = $data['description'] ?? '';

        if (empty($description)) {
            return $fallback;
        }

        // Formato: "Nombre: Descripción"
        return trim("{$name}: {$description}");
    }

    /**
     * Fast SQL lookup — sin API calls.
     *   1. Exact slug (rawText normalizado a snake_case)
     *   2. Case-insensitive name match
     */
    private function findByText(string $rawText): ?CanonicalTag
    {
        $slug = Str::slug($rawText, '_');

        return CanonicalTag::where('is_active', true)
            ->where(function ($q) use ($rawText, $slug): void {
                $q->where('slug', $slug)
                  ->orWhereRaw('LOWER(name) = LOWER(?)', [$rawText]);
            })
            ->first();
    }

    private function resolveGatekeeperPrompt(?string $archetypeId): ?string
    {
        if ($archetypeId === null) {
            return null;
        }

        $archetype = Archetype::with('prompts')->find($archetypeId);

        return $archetype?->getPromptFor('gatekeeper');
    }
}
