<?php

namespace App\Domains\Matchmaking\Contracts;

use App\Domains\Matchmaking\DTOs\MatchResultDTO;

interface MatchmakingRepositoryInterface
{
    /**
     * Encuentra jugadores compatibles usando el perfil del usuario como query.
     *
     * @return list<MatchResultDTO>
     */
    public function findCompatiblePlayers(
        string $discordUserId,
        string $archetypeVectorName,
        string $discordGuildId,
        int $limit = 5,
    ): array;

    /**
     * Encuentra jugadores compatibles usando el texto de una activity como query,
     * aplicando pre-filtro de IDs de perfil antes del semantic search.
     *
     * @param  string        $activityOptimizedText  optimized_text_en del Avatar activity
     * @param  string        $archetypeVectorName    Nombre del vector Qdrant
     * @param  string        $discordGuildId         Contexto del servidor
     * @param  list<string>  $preFilteredProfileIds  IDs de PlayerArchetypeProfile permitidos (vacío = sin filtro)
     * @param  int           $limit
     * @return list<MatchResultDTO>
     */
    public function findCompatiblePlayersForActivity(
        string $activityOptimizedText,
        string $archetypeVectorName,
        string $discordGuildId,
        array $preFilteredProfileIds = [],
        int $limit = 5,
    ): array;
}
