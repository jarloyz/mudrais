<?php

namespace App\Services;

use App\Application\Services\QdrantService;
use App\Models\CanonicalTag;
use App\Models\Player;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class MatchmakingService
{
    public function __construct(
        private readonly QdrantService $qdrant,
    ) {}

    /**
     * Encuentra los mejores matches para un jugador.
     *
     * Fase 1 — Filtrado duro en Qdrant:
     *   - must:     guild_id, is_active
     *   - must_not: propio player_id, preferencias del buscador vs red_lines del candidato,
     *               red_lines del buscador vs preferencias del candidato
     *
     * Fase 2 — Re-ranking ponderado en Laravel:
     *   - Bonus por preferencias compartidas (peso por posición en el array)
     *   - Penalización por cruces con yellow_lines
     *
     * @param  array<float>  $queryVector
     * @return list<array{discord_id:string,username:string,vector_score:float,bonus_points:float,penalty_points:float,final_score:float,shared_tags:list<string>,warnings:list<string>,vibe_summary:string}>
     */
    public function findPartnership(Player $searcher, array $queryVector, ?string $guildId = null): array
    {
        $searcherRedLines = $searcher->tagsByContext('red_line')->pluck('slug')->all();
        $searcherPrefs    = $searcher->tagsByContext('preference')->pluck('slug')->all();
        $searcherYellows  = $searcher->tagsByContext('yellow_line')->pluck('slug')->all();

        Log::debug('MatchmakingService: searcher profile loaded.', [
            'searcher_id'   => $searcher->id,
            'username'      => $searcher->username,
            'guild_id'      => $guildId,
            'red_lines'     => $searcherRedLines,
            'yellow_lines'  => $searcherYellows,
            'preferences'   => $searcherPrefs,
            'vector_length' => count($queryVector),
        ]);

        $must = [
            ['key' => 'entity_type', 'match' => ['value' => 'player']],
        ];

        if ($guildId) {
            $must[] = ['key' => 'guild_ids', 'match' => ['value' => $guildId]];
        }

        $mustNot = [
            ['key' => 'player_id', 'match' => ['value' => (string) ($searcher->id)]],
        ];

        if (! empty($searcherRedLines)) {
            $mustNot[] = ['key' => 'preferences_tags', 'match' => ['any' => $searcherRedLines]];
        }

        if (! empty($searcherPrefs)) {
            $mustNot[] = ['key' => 'red_lines_tags', 'match' => ['any' => $searcherPrefs]];
        }

        Log::debug('MatchmakingService: Qdrant filter built.', [
            'searcher_id' => $searcher->id,
            'must'        => $must,
            'must_not'    => $mustNot,
        ]);

        $candidates = $this->qdrant->searchProfilesAdvanced($queryVector, $must, $mustNot, 20);

        Log::debug('MatchmakingService: candidates retrieved.', [
            'searcher_id' => $searcher->id,
            'count'       => count($candidates),
            'raw_results' => array_map(fn($c) => [
                'id'      => $c['id'] ?? null,
                'score'   => $c['score'] ?? null,
                'payload' => array_diff_key($c['payload'] ?? [], ['text' => '']),
            ], $candidates),
        ]);

        // Enrich candidates with DB profile data
        $playerIds = array_map(fn($c) => (string) ($c['payload']['player_id'] ?? $c['id']), $candidates);
        $dbPlayers = Player::whereIn('id', $playerIds)
            ->get(['id', 'username', 'discord_id', 'country_code', 'nationality', 'experience_level', 'verbosity_level', 'schedule_raw', 'about_me'])
            ->keyBy('id');

        Log::debug('MatchmakingService: DB profiles fetched.', [
            'searcher_id'  => $searcher->id,
            'player_ids'   => $playerIds,
            'found_in_db'  => $dbPlayers->keys()->all(),
        ]);

        foreach ($candidates as &$candidate) {
            $dbId     = (string) ($candidate['payload']['player_id'] ?? $candidate['id']);
            $dbPlayer = $dbPlayers->get($dbId);

            if ($dbPlayer) {
                $candidate['payload']['username']         = $dbPlayer->username;
                $candidate['payload']['discord_id']       = $dbPlayer->discord_id;
                $candidate['payload']['country_code']     = $dbPlayer->country_code;
                $candidate['payload']['nationality']      = $dbPlayer->nationality;
                $candidate['payload']['experience_level'] = $dbPlayer->experience_level;
                $candidate['payload']['verbosity_level']  = $dbPlayer->verbosity_level;
                $candidate['payload']['schedule_raw']     = $dbPlayer->schedule_raw;
                $candidate['payload']['about_me']         = $dbPlayer->about_me;
            }
        }
        unset($candidate);

        $slugToName = CanonicalTag::whereIn('slug', $searcherPrefs)
            ->pluck('name', 'slug')
            ->all();

        Log::debug('MatchmakingService: slug→name map loaded.', [
            'searcher_id' => $searcher->id,
            'map_count'   => count($slugToName),
        ]);

        $ranked = $this->scoreAndRankCandidates($searcherPrefs, $searcherYellows, $candidates, $slugToName);

        Log::debug('MatchmakingService: ranking complete.', [
            'searcher_id' => $searcher->id,
            'top_results' => array_map(fn($r) => [
                'username'      => $r['username'],
                'vector_score'  => $r['vector_score'],
                'bonus'         => $r['bonus_points'],
                'penalty'       => $r['penalty_points'],
                'final_score'   => $r['final_score'],
                'shared_tags'   => $r['shared_tags'],
                'warnings'      => $r['warnings'],
            ], $ranked),
        ]);

        return $ranked;
    }

    /**
     * Formatea y rankea los resultados crudos de mudrais_profiles (B2B Named Vectors).
     * El payload de mudrais_profiles contiene: discord_user_id, red_lines, yellow_lines, meta_verbosity.
     *
     * @param  list<array{id:string,score:float,payload:array<string,mixed>}> $rawResults
     * @return list<array>
     */
    public function formatArchetypeResults(Player $searcher, array $rawResults): array
    {
        if (empty($rawResults)) {
            return [];
        }

        $searcherRedLines = $searcher->tagsByContext('red_line')->pluck('slug')->all();
        $searcherYellows  = $searcher->tagsByContext('yellow_line')->pluck('slug')->all();
        $searcherPrefs    = $searcher->tagsByContext('preference')->pluck('slug')->all();

        $discordIds = array_map(fn($r) => (string) ($r['payload']['discord_user_id'] ?? ''), $rawResults);
        $discordIds = array_filter($discordIds);

        $dbPlayers = Player::whereIn('discord_id', $discordIds)
            ->get(['id', 'discord_id', 'username', 'country_code', 'nationality', 'experience_level', 'verbosity_level', 'schedule_raw', 'about_me'])
            ->keyBy('discord_id');

        $slugToName = CanonicalTag::whereIn('slug', $searcherPrefs)->pluck('name', 'slug')->all();

        $scored = collect($rawResults)->map(function (array $result) use ($searcher, $searcherPrefs, $searcherYellows, $dbPlayers, $slugToName) {
            $payload         = $result['payload'] ?? [];
            $discordUserId   = (string) ($payload['discord_user_id'] ?? '');
            $dbPlayer        = $dbPlayers->get($discordUserId);
            $vectorScore     = round((float) ($result['score'] ?? 0) * 100, 2);
            $penaltyScore    = 0.0;
            $bonusScore      = 0.0;
            $warnings        = [];
            $sharedPrefs     = [];

            // Skip self
            if ($discordUserId === $searcher->discord_id) {
                return null;
            }

            $candidateYellows = (array) ($payload['yellow_lines'] ?? []);
            $candidatePrefs   = [];

            // Penalización por yellow_lines
            foreach (array_intersect($searcherYellows, $candidatePrefs) as $yellow) {
                $penaltyScore += 25;
                $warnings[]    = "Tu línea amarilla ({$yellow}) es su preferencia.";
            }

            foreach (array_intersect($candidateYellows, $searcherPrefs) as $yellow) {
                $penaltyScore += 25;
                $warnings[]    = "Tu preferencia ({$yellow}) es su línea amarilla.";
            }

            $finalScore = $vectorScore + $bonusScore - $penaltyScore;

            return [
                'player_id'      => $dbPlayer?->id,
                'discord_id'     => $discordUserId,
                'username'       => $dbPlayer?->username ?? 'Usuario',
                'country_code'   => $dbPlayer?->country_code ?? null,
                'nationality'    => $dbPlayer?->nationality ?? null,
                'exp_level'      => $dbPlayer?->experience_level ?? null,
                'verb_level'     => $dbPlayer?->verbosity_level ?? null,
                'schedule_raw'   => $dbPlayer?->schedule_raw ?? null,
                'about_me'       => $dbPlayer?->about_me ?? null,
                'vector_score'   => $vectorScore,
                'bonus_points'   => $bonusScore,
                'penalty_points' => $penaltyScore,
                'final_score'    => $finalScore,
                'shared_tags'    => array_map(fn($s) => $slugToName[$s] ?? $s, $sharedPrefs),
                'warnings'       => $warnings,
                'vibe_summary'   => '',
            ];
        });

        return $scored->filter()->sortByDesc('final_score')->take(5)->values()->all();
    }

    /**
     * Aplica bonificaciones por preferencias compartidas (posicionales) y
     * penalizaciones por cruces con yellow_lines.
     *
     * @param  list<string>  $searcherPrefs
     * @param  list<string>  $searcherYellows
     * @param  list<array>   $candidates
     * @return list<array>
     */
    private function scoreAndRankCandidates(array $searcherPrefs, array $searcherYellows, array $candidates, array $slugToName = []): array
    {
        $scored = collect($candidates)->map(function (array $candidate) use ($searcherPrefs, $searcherYellows, $slugToName) {
            $payload      = $candidate['payload'] ?? [];
            $vectorScore  = round((float) ($candidate['score'] ?? 0) * 100, 2);
            $bonusScore   = 0.0;
            $penaltyScore = 0.0;
            $sharedPrefs  = [];
            $warnings     = [];

            $candidatePrefs   = $payload['preferences_tags']  ?? [];
            $candidateYellows = $payload['yellow_lines_tags'] ?? [];

            // A. Bonificación por preferencias compartidas (peso posicional)
            // Fórmula: 20 pts base − 3 por posición del buscador − 2 por posición del candidato.
            // Garantía mínima de 5 pts por cualquier coincidencia real.
            foreach ($searcherPrefs as $si => $pref) {
                $ci = array_search($pref, $candidatePrefs, true);

                if ($ci !== false) {
                    $sharedPrefs[] = $pref;
                    $weight        = 20 - ($si * 3) - ($ci * 2);
                    $bonusScore   += max(5, $weight);
                }
            }

            // B. Penalización por yellow_lines (filtro suave, −25 pts por cruce)
            foreach (array_intersect($searcherYellows, $candidatePrefs) as $yellow) {
                $penaltyScore += 25;
                $warnings[]    = "Tu línea amarilla ({$yellow}) es su preferencia.";
            }

            foreach (array_intersect($candidateYellows, $searcherPrefs) as $yellow) {
                $penaltyScore += 25;
                $warnings[]    = "Tu preferencia ({$yellow}) es su línea amarilla.";
            }

            $finalScore = $vectorScore + $bonusScore - $penaltyScore;

            return [
                'player_id'      => ($payload['player_id'] ?? $candidate['id']),
                'discord_id'     => (string) ($payload['discord_id'] ?? $payload['player_id'] ?? $candidate['id']),
                'username'       => $payload['username']      ?? 'Usuario',
                'country_code'   => $payload['country_code']  ?? null,
                'nationality'    => $payload['nationality']   ?? null,
                'exp_level'      => $payload['experience_level'] ?? null,
                'verb_level'     => $payload['verbosity_level']  ?? null,
                'schedule_raw'   => $payload['schedule_raw']  ?? null,
                'about_me'       => $payload['about_me']      ?? null,
                'vector_score'   => $vectorScore,
                'bonus_points'   => $bonusScore,
                'penalty_points' => $penaltyScore,
                'final_score'    => $finalScore,
                'shared_tags'    => array_map(fn(string $slug) => $slugToName[$slug] ?? $slug, $sharedPrefs),
                'warnings'       => $warnings,
                'vibe_summary'   => $payload['text'] ?? '',
            ];
        });

        return $scored->sortByDesc('final_score')->take(5)->values()->all();
    }

    /**
     * Formatea los resultados crudos de matchmaking_hub (entidades como Avatares, Activities, etc.).
     *
     * @param  Player $searcher
     * @param  list<array{id:string,score:float,payload:array<string,mixed>}> $rawResults
     * @param  \App\Domains\Matchmaking\Models\ArchetypeEntityType $entityType
     * @return list<array>
     */
    public function formatHubResults(Player $searcher, array $rawResults, $entityType): array
    {
        Log::debug('[MatchmakingService] formatHubResults entry', [
            'entity_type' => $entityType->entity,
            'raw_count'   => count($rawResults),
            'searcher_id' => $searcher->id,
        ]);

        if (empty($rawResults)) {
            return [];
        }

        $ids = array_map(fn($r) => $r['payload'][$entityType->entity . '_id'] ?? null, $rawResults);
        $ids = array_filter($ids);

        Log::debug('[MatchmakingService] formatHubResults ids', [
            'entity_key' => $entityType->entity . '_id',
            'ids'        => array_values($ids),
        ]);

        $dbEntities = collect();
        if ($entityType->entity === 'avatar' || $entityType->entity === 'character') {
            $dbEntities = \App\Domains\Narrative\Models\Avatar::with('ownerProfile.player')
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            Log::debug('[MatchmakingService] formatHubResults dbEntities', [
                'found' => $dbEntities->keys()->all(),
            ]);
        } elseif ($entityType->entity === 'activity') {
            $dbEntities = \App\Domains\Narrative\Models\Activity::with('creatorProfile.player')
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        } elseif ($entityType->entity === 'vault') {
            $dbEntities = \App\Domains\Narrative\Models\Vault::whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        }

        $allTagIds = collect($rawResults)
            ->flatMap(fn($r) => $r['payload']['tags'] ?? [])
            ->unique()->values()->all();

        $tagIdToName = empty($allTagIds)
            ? []
            : CanonicalTag::whereIn('id', $allTagIds)->pluck('name', 'id')->all();

        $scored = collect($rawResults)->map(function (array $result) use ($entityType, $dbEntities, $searcher, $tagIdToName) {
            $payload = $result['payload'] ?? [];
            $entityId = $payload[$entityType->entity . '_id'] ?? null;
            $dbEntity = $dbEntities->get($entityId);

            if (!$dbEntity) {
                Log::warning('[MatchmakingService] formatHubResults: entity not found in DB', [
                    'entity_type' => $entityType->entity,
                    'entity_id'   => $entityId,
                    'qdrant_id'   => $result['id'] ?? null,
                    'payload_keys' => array_keys($payload),
                ]);
                return null;
            }

            // Exclude user's own creations safely
            $creatorId = null;
            $creatorName = 'Desconocido';
            $creatorDiscordId = null;

            if ($entityType->entity === 'avatar' || $entityType->entity === 'character') {
                $creatorId = $dbEntity->ownerProfile?->player_id;
                $creatorName = $dbEntity->ownerProfile?->player?->username ?? 'Desconocido';
                $creatorDiscordId = $dbEntity->ownerProfile?->player?->discord_id;
            } elseif ($entityType->entity === 'activity') {
                $creatorId = $dbEntity->creatorProfile?->player_id;
                $creatorName = $dbEntity->creatorProfile?->player?->username ?? 'Desconocido';
                $creatorDiscordId = $dbEntity->creatorProfile?->player?->discord_id;
            }

            $excludeOwn = $entityType->matching_filters['exclude_own'] ?? false;
            if ($excludeOwn && $creatorId === $searcher->id) {
                Log::debug('[MatchmakingService] formatHubResults: excludiendo propio', [
                    'entity_id'  => $entityId,
                    'creator_id' => $creatorId,
                ]);
                return null;
            }

            $name = $dbEntity->name ?? $dbEntity->title ?? 'Entidad Sin Nombre';

            // Generate a small description or summary
            $description = 'Sin descripción.';
            if ($entityType->entity === 'avatar' || $entityType->entity === 'character') {
                $raw = is_array($dbEntity->content_raw) ? $dbEntity->content_raw : [];
                $description = $raw['synopsis'] ?? $raw['description'] ?? $raw['summary'] ?? null;
                if (! $description) {
                    $description = $dbEntity->getOptimizedText() ?? 'Avatar indexado.';
                }
                $description = mb_strimwidth((string) $description, 0, 150, '…');
            } elseif ($entityType->entity === 'activity') {
                $description = $dbEntity->content_raw['description'] ?? $dbEntity->optimized_text ?? 'Actividad indexada.';
            } elseif ($entityType->entity === 'vault') {
                $description = $dbEntity->description ?? 'Vault indexado.';
            }

            return [
                'id'                 => $result['id'],
                'score'              => $result['score'] ?? 0.0,
                'name'               => $name,
                'description'        => $description,
                'entity_id'          => $payload['activity_id'] ?? $payload['avatar_id'] ?? null,
                'creator_name'       => $creatorName,
                'creator_discord_id' => $creatorDiscordId,
                'tags'               => array_values(array_filter(
                                          array_map(fn($id) => $tagIdToName[$id] ?? null, $payload['tags'] ?? [])
                                      )),
                'ctx1_id'            => $dbEntity->content_raw['ctx1_id']   ?? null,
                'ctx1_name'          => $dbEntity->content_raw['ctx1_name'] ?? null,
                'ctx2_id'            => $dbEntity->content_raw['ctx2_id']   ?? null,
                'ctx2_name'          => $dbEntity->content_raw['ctx2_name'] ?? null,
                'created_at'         => $dbEntity->created_at,
                'discord_thread_id'  => $dbEntity->discord_thread_id ?? null,
            ];
        });

        return $scored->filter()->sortByDesc('score')->take(5)->values()->all();
    }}
