<?php

namespace App\Repositories;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Models\Player;
use App\Support\UserAiSettingsResolver;

class PlayerMatchmakingRepository
{
    public function __construct(
        private readonly QdrantService          $qdrant,
        private readonly EmbeddingGateway       $embeddingGateway,
        private readonly UserAiSettingsResolver $resolver,
    ) {}

    /**
     * Find compatible players by searching the players_profiles Qdrant collection.
     *
     * Supported filters:
     *   experience_level (int 1-5)
     *   verbosity_level  (int 1-5)
     *   red_lines_to_avoid (string[]) — exclude players whose red_lines_tags match any of these
     *
     * @param  array<string, mixed> $filters
     * @return list<array{player: Player, score: float}>
     */
    public function findCompatiblePlayers(string $queryText, array $filters = [], ?string $guildId = null, int $limit = 10): array
    {
        $embeddingModel = $this->resolver->resolveAgentModel(null, 'embedding');

        $vector = $this->embeddingGateway->embed($embeddingModel, $queryText);

        if (empty($vector)) {
            return [];
        }

        if ($guildId) {
            $filters['guild_id'] = $guildId;
        }

        $results = $this->qdrant->searchProfiles($vector, $filters, $limit);

        $playersWithScores = [];
        foreach ($results as $res) {
            $player = Player::find($res['id']);
            if ($player) {
                $playersWithScores[] = [
                    'player' => $player,
                    'score'  => (float) ($res['score'] ?? 0),
                ];
            }
        }

        return $playersWithScores;
    }
}
