<?php

namespace App\Domains\Matchmaking\Infrastructure;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Contracts\MatchmakingRepositoryInterface;
use App\Domains\Matchmaking\DTOs\MatchResultDTO;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Log;

class QdrantMatchmakingRepository implements MatchmakingRepositoryInterface
{
    public function __construct(
        private readonly QdrantService $qdrant,
        private readonly EmbeddingGateway $embeddingGateway,
        private readonly UserAiSettingsResolver $resolver,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function findCompatiblePlayers(
        string $discordUserId,
        string $archetypeVectorName,
        string $discordGuildId,
        int $limit = 5,
    ): array {
        Log::channel('qdrant')->debug('[QdrantMatchmakingRepository@findCompatiblePlayers] Inicio.', [
            'discord_user_id'     => $discordUserId,
            'archetype_vector'    => $archetypeVectorName,
            'discord_guild_id'    => $discordGuildId,
            'limit'               => $limit,
        ]);

        // Obtener perfil del usuario para usar como query text
        $profile = \App\Models\PlayerArchetypeProfile::where('discord_user_id', $discordUserId)
            ->whereHas('archetype', fn ($q) => $q->where('qdrant_vector_name', $archetypeVectorName))
            ->first();

        $queryText = $profile?->optimized_text ?? $discordUserId;

        $embeddingModel = $this->resolver->resolveAgentModel(null, 'embedding');
        $vector = $this->embeddingGateway->embed($embeddingModel, $queryText);

        if (empty($vector)) {
            Log::channel('qdrant')->warning('[QdrantMatchmakingRepository@findCompatiblePlayers] Vector vacío.', [
                'discord_user_id' => $discordUserId,
            ]);
            return [];
        }

        $filters = ['guild_id' => $discordGuildId];
        $results = $this->qdrant->searchProfiles($vector, $filters, $limit);

        Log::channel('qdrant')->info('[QdrantMatchmakingRepository@findCompatiblePlayers] Resultados obtenidos.', [
            'count' => count($results),
        ]);

        return array_map(
            fn ($res) => new MatchResultDTO(
                discordUserId: (string) ($res['id'] ?? ''),
                score: (float) ($res['score'] ?? 0),
                metadata: $res['payload'] ?? [],
            ),
            $results
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findCompatiblePlayersForActivity(
        string $activityOptimizedText,
        string $archetypeVectorName,
        string $discordGuildId,
        array $preFilteredProfileIds = [],
        int $limit = 5,
    ): array {
        Log::channel('qdrant')->debug('[QdrantMatchmakingRepository@findCompatiblePlayersForActivity] Inicio.', [
            'archetype_vector'        => $archetypeVectorName,
            'discord_guild_id'        => $discordGuildId,
            'pre_filtered_count'      => count($preFilteredProfileIds),
            'limit'                   => $limit,
        ]);

        $embeddingModel = $this->resolver->resolveAgentModel(null, 'embedding');
        $vector         = $this->embeddingGateway->embed($embeddingModel, $activityOptimizedText);

        if (empty($vector)) {
            Log::channel('qdrant')->warning('[QdrantMatchmakingRepository@findCompatiblePlayersForActivity] Vector vacío.');
            return [];
        }

        $filters = ['guild_id' => $discordGuildId];

        if (! empty($preFilteredProfileIds)) {
            $filters['profile_ids'] = $preFilteredProfileIds;
        }

        $results = $this->qdrant->searchProfiles($vector, $filters, $limit);

        Log::channel('qdrant')->info('[QdrantMatchmakingRepository@findCompatiblePlayersForActivity] Resultados.', [
            'count' => count($results),
        ]);

        return array_map(
            fn ($res) => new MatchResultDTO(
                discordUserId: (string) ($res['id'] ?? ''),
                score: (float) ($res['score'] ?? 0),
                metadata: $res['payload'] ?? [],
            ),
            $results
        );
    }
}
