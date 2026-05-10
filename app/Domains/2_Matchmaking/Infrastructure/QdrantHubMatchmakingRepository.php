<?php

namespace App\Domains\Matchmaking\Infrastructure;

use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Contracts\HubMatchmakingRepositoryInterface;
use App\Domains\Matchmaking\DTOs\HubMatchResultDTO;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Avatar;
use Illuminate\Support\Facades\Log;

class QdrantHubMatchmakingRepository implements HubMatchmakingRepositoryInterface
{
    public function __construct(private readonly QdrantService $qdrant) {}

    public function findActivitiesForPlayer(string $discordUserId, string $archetypeId, string $guildId, int $limit = 10): array
    {
        $profile = PlayerArchetypeProfile::where('discord_user_id', $discordUserId)
            ->where('archetype_id', $archetypeId)
            ->first();

        if (!$profile || empty($profile->player_style_vector)) {
            Log::channel('qdrant')->warning("QdrantHubMatchmakingRepository: Missing profile or vector for user {$discordUserId} (LFG).");
            return [];
        }

        $must = [
            ['key' => 'entity_type', 'match' => ['value' => 'activity']],
            ['key' => 'status', 'match' => ['value' => 1]], // 1 = RECRUITING
            ['key' => 'guild_ids', 'match' => ['value' => $guildId]],
        ];

        // Excluir actividades estrictamente inbound (no visibles para jugadores).
        // Usar must_not en lugar de must para retrocompatibilidad: los puntos existentes
        // sin el campo search_direction siguen apareciendo correctamente.
        $mustNot = [
            ['key' => 'search_direction', 'match' => ['value' => 'inbound']],
        ];

        $points = $this->qdrant->searchHub(
            vectorName: 'player_style',
            queryVector: $profile->player_style_vector,
            must: $must,
            mustNot: $mustNot,
            limit: $limit
        );

        return array_map(fn($p) => HubMatchResultDTO::fromQdrantPoint($p), $points);
    }

    public function findAvatarsForActivity(string $activityId, string $guildId, int $limit = 10): array
    {
        $activity = Activity::find($activityId);
        if (!$activity || !$activity->activity_hub_qdrant_id) {
            Log::channel('qdrant')->warning("QdrantHubMatchmakingRepository: Activity {$activityId} missing or not in hub.");
            return [];
        }

        $vibeVector = $this->qdrant->getHubVector($activity->activity_hub_qdrant_id, 'activity_vibe');
        if (empty($vibeVector)) {
            Log::channel('qdrant')->warning("QdrantHubMatchmakingRepository: Activity {$activityId} missing activity_vibe vector.");
            return [];
        }

        $must = [
            ['key' => 'entity_type', 'match' => ['value' => 'avatar']],
            ['key' => 'is_lfg', 'match' => ['value' => true]],
            ['key' => 'guild_ids', 'match' => ['value' => $guildId]],
        ];

        $points = $this->qdrant->searchHub(
            vectorName: 'avatar_context',
            queryVector: $vibeVector,
            must: $must,
            mustNot: [],
            limit: $limit
        );

        return array_map(fn($p) => HubMatchResultDTO::fromQdrantPoint($p), $points);
    }

    public function findCompatiblePlayersP2P(string $discordUserId, string $archetypeId, string $guildId, int $limit = 10): array
    {
        $profile = PlayerArchetypeProfile::where('discord_user_id', $discordUserId)
            ->where('archetype_id', $archetypeId)
            ->first();

        if (!$profile || empty($profile->player_style_vector)) {
            Log::channel('qdrant')->warning("QdrantHubMatchmakingRepository: Missing profile or vector for user {$discordUserId} (P2P).");
            return [];
        }

        $must = [
            ['key' => 'entity_type', 'match' => ['value' => 'player_profile']],
            ['key' => 'archetype_id', 'match' => ['value' => $archetypeId]],
            ['key' => 'guild_ids', 'match' => ['value' => $guildId]],
        ];

        $mustNot = [];
        // test_p2p_excludes_requester_red_lines_in_must_not
        $redLines = $profile->red_lines ?? [];
        foreach ($redLines as $redLine) {
            $mustNot[] = ['key' => 'red_lines', 'match' => ['value' => $redLine]];
        }

        $points = $this->qdrant->searchHub(
            vectorName: 'player_style',
            queryVector: $profile->player_style_vector,
            must: $must,
            mustNot: $mustNot,
            limit: $limit
        );

        return array_map(fn($p) => HubMatchResultDTO::fromQdrantPoint($p), $points);
    }

    public function findProfilesForActivity(Activity $activity, string $guildId, bool $filterAvailable = false, int $limit = 10): array
    {
        Log::debug('[QdrantHubMatchmakingRepository@findProfilesForActivity] Inicio', [
            'activity_id'      => $activity->id,
            'guild_id'         => $guildId,
            'filter_available' => $filterAvailable,
        ]);

        $ctx1Id = $activity->content_raw['ctx1_id'] ?? null;
        $ctx1   = $ctx1Id ? Avatar::find($ctx1Id) : null;

        if (! $ctx1 || empty($ctx1->avatar_context_vector)) {
            Log::channel('qdrant')->warning(
                '[QdrantHubMatchmakingRepository@findProfilesForActivity] Sin ctx1 indexado.',
                ['activity_id' => $activity->id, 'ctx1_id' => $ctx1Id]
            );
            return [];
        }

        $archetypeId = $activity->creatorProfile?->archetype_id;
        if (! $archetypeId) {
            Log::channel('qdrant')->warning(
                '[QdrantHubMatchmakingRepository@findProfilesForActivity] Sin archetype_id en creatorProfile.',
                ['activity_id' => $activity->id]
            );
            return [];
        }

        $must = [
            ['key' => 'entity_type', 'match' => ['value' => 'player_profile']],
            ['key' => 'archetype_id', 'match' => ['value' => $archetypeId]],
            ['key' => 'guild_ids', 'match' => ['value' => $guildId]],
        ];

        if ($filterAvailable) {
            $must[] = ['key' => 'is_available', 'match' => ['value' => true]];
        }

        Log::info('[QdrantHubMatchmakingRepository@findProfilesForActivity] Buscando profiles con avatar_context_vector', [
            'activity_id' => $activity->id,
            'ctx1_id'     => $ctx1->id,
            'archetype_id'=> $archetypeId,
            'filter_available' => $filterAvailable,
        ]);

        // Cross-query: avatar_context_vector como query vector contra player_style named vectors.
        // Ambos vectores son 2048D del mismo modelo, semánticamente compatibles.
        $points = $this->qdrant->searchHub(
            vectorName: 'player_style',
            queryVector: $ctx1->avatar_context_vector,
            must: $must,
            mustNot: [],
            limit: $limit
        );

        Log::info('[QdrantHubMatchmakingRepository@findProfilesForActivity] Resultados obtenidos', [
            'activity_id' => $activity->id,
            'count'       => count($points),
        ]);

        return array_map(fn($p) => HubMatchResultDTO::fromQdrantPoint($p), $points);
    }

    public function findProfilesForTeamActivity(Activity $parent, string $guildId, bool $filterAvailable = false): array
    {
        Log::debug('[QdrantHubMatchmakingRepository@findProfilesForTeamActivity] Inicio', [
            'parent_id'        => $parent->id,
            'required_slots'   => $parent->required_slots,
            'filter_available' => $filterAvailable,
        ]);

        $results = [];

        foreach ($parent->childActivities as $slot) {
            Log::debug('[QdrantHubMatchmakingRepository@findProfilesForTeamActivity] Buscando para slot', [
                'slot_id'    => $slot->id,
                'slot_title' => $slot->title,
            ]);

            $results[$slot->id] = [
                'slot_title' => $slot->title,
                'candidates' => $this->findProfilesForActivity($slot, $guildId, $filterAvailable),
            ];
        }

        Log::info('[QdrantHubMatchmakingRepository@findProfilesForTeamActivity] Búsqueda completada', [
            'parent_id'    => $parent->id,
            'slots_queried'=> count($results),
        ]);

        return $results;
    }

    public function findProfilesForProjectActivity(
        Activity $activity,
        string   $guildId,
        bool     $filterAvailable = false,
        int      $limitPerRole    = 10
    ): array {
        Log::debug('[QdrantHubMatchmakingRepository@findProfilesForProjectActivity] Inicio', [
            'activity_id'      => $activity->id,
            'guild_id'         => $guildId,
            'filter_available' => $filterAvailable,
        ]);

        $roleIds     = $activity->content_raw['roles']    ?? [];
        $contextIds  = $activity->content_raw['contexts'] ?? [];
        $archetypeId = $activity->creatorProfile?->archetype_id;

        if (empty($roleIds) || ! $archetypeId) {
            Log::channel('qdrant')->warning(
                '[QdrantHubMatchmakingRepository@findProfilesForProjectActivity] Sin roles o archetype_id.',
                ['activity_id' => $activity->id]
            );
            return [];
        }

        $contextBlend = $this->buildContextBlend($contextIds);

        $must = [
            ['key' => 'entity_type', 'match' => ['value' => 'player_profile']],
            ['key' => 'archetype_id', 'match' => ['value' => $archetypeId]],
            ['key' => 'guild_ids',   'match' => ['value' => $guildId]],
        ];

        if ($filterAvailable) {
            $must[] = ['key' => 'is_available', 'match' => ['value' => true]];
        }

        $results       = [];
        $seenQdrantIds = [];

        foreach ($roleIds as $avatarId) {
            $avatar = Avatar::find($avatarId);

            if (! $avatar || empty($avatar->avatar_context_vector)) {
                Log::channel('qdrant')->warning(
                    '[QdrantHubMatchmakingRepository@findProfilesForProjectActivity] Role sin vector.',
                    ['avatar_id' => $avatarId]
                );
                continue;
            }

            $queryVector = $contextBlend
                ? $this->blendVectors($avatar->avatar_context_vector, $contextBlend, roleWeight: 0.7)
                : $avatar->avatar_context_vector;

            Log::debug('[QdrantHubMatchmakingRepository@findProfilesForProjectActivity] Buscando role', [
                'avatar_id'     => $avatar->id,
                'avatar_name'   => $avatar->name,
                'context_blend' => $contextBlend !== null,
            ]);

            $points = $this->qdrant->searchHub(
                vectorName: 'player_style',
                queryVector: $queryVector,
                must: $must,
                mustNot: [],
                limit: $limitPerRole,
            );

            $candidates = [];
            foreach ($points as $point) {
                $dto = HubMatchResultDTO::fromQdrantPoint($point);
                if (! in_array($dto->qdrantId, $seenQdrantIds, strict: true)) {
                    $seenQdrantIds[] = $dto->qdrantId;
                    $candidates[]    = $dto;
                }
            }

            $results[$avatarId] = [
                'role_name'  => $avatar->name,
                'candidates' => $candidates,
            ];

            Log::info('[QdrantHubMatchmakingRepository@findProfilesForProjectActivity] Role procesado', [
                'avatar_id'        => $avatarId,
                'candidates_found' => count($candidates),
            ]);
        }

        Log::info('[QdrantHubMatchmakingRepository@findProfilesForProjectActivity] Completado', [
            'activity_id'   => $activity->id,
            'roles_queried' => count($results),
        ]);

        return $results;
    }

    private function buildContextBlend(array $contextIds): ?array
    {
        if (empty($contextIds)) {
            return null;
        }

        $vectors = Avatar::whereIn('id', $contextIds)
            ->whereNotNull('avatar_context_vector')
            ->pluck('avatar_context_vector')
            ->filter()
            ->values();

        if ($vectors->isEmpty()) {
            return null;
        }

        $dim = count($vectors->first());
        $sum = array_fill(0, $dim, 0.0);

        foreach ($vectors as $v) {
            foreach ($v as $i => $val) {
                $sum[$i] += (float) $val;
            }
        }

        $count = $vectors->count();
        return array_map(fn($x) => $x / $count, $sum);
    }

    private function blendVectors(array $roleVec, array $contextVec, float $roleWeight): array
    {
        $contextWeight = 1.0 - $roleWeight;
        $blended = [];
        foreach ($roleVec as $i => $val) {
            $blended[$i] = $roleWeight * (float) $val + $contextWeight * (float) ($contextVec[$i] ?? 0.0);
        }
        return $blended;
    }
}
