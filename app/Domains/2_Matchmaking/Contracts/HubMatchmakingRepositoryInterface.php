<?php

namespace App\Domains\Matchmaking\Contracts;

interface HubMatchmakingRepositoryInterface
{
    /**
     * @return \App\Domains\Matchmaking\DTOs\HubMatchResultDTO[]
     */
    public function findActivitiesForPlayer(string $discordUserId, string $archetypeId, string $guildId, int $limit = 10): array;

    /**
     * @return \App\Domains\Matchmaking\DTOs\HubMatchResultDTO[]
     */
    public function findAvatarsForActivity(string $activityId, string $guildId, int $limit = 10): array;

    /**
     * @return \App\Domains\Matchmaking\DTOs\HubMatchResultDTO[]
     */
    public function findCompatiblePlayersP2P(string $discordUserId, string $archetypeId, string $guildId, int $limit = 10): array;

    /**
     * Búsqueda inbound: usa el avatar_context_vector de ctx1 de la actividad
     * como query vector para encontrar player_profiles compatibles.
     *
     * @return \App\Domains\Matchmaking\DTOs\HubMatchResultDTO[]
     */
    public function findProfilesForActivity(
        \App\Domains\Narrative\Models\Activity $activity,
        string $guildId,
        bool $filterAvailable = false,
        int $limit = 10
    ): array;

    /**
     * Team search: ejecuta findProfilesForActivity para cada actividad hija
     * y devuelve un mapa [slotActivityId => ['slot_title' => string, 'candidates' => HubMatchResultDTO[]]].
     */
    public function findProfilesForTeamActivity(
        \App\Domains\Narrative\Models\Activity $parent,
        string $guildId,
        bool $filterAvailable = false
    ): array;

    /**
     * Multi-role search con context blending opcional.
     *
     * Lee content_raw['roles'] (array de avatar IDs de tipo tech_profile) y ejecuta
     * una búsqueda de player_profiles por cada role usando su avatar_context_vector.
     *
     * Si content_raw['contexts'] contiene avatars adicionales, su vector se promedia
     * y se mezcla con el de cada role (0.3 weight) antes de buscar.
     *
     * Deduplica candidatos cross-role por qdrantId.
     *
     * @return array<string, array{role_name: string, candidates: \App\Domains\Matchmaking\DTOs\HubMatchResultDTO[]}>
     */
    public function findProfilesForProjectActivity(
        \App\Domains\Narrative\Models\Activity $activity,
        string $guildId,
        bool $filterAvailable = false,
        int $limitPerRole = 10
    ): array;
}
