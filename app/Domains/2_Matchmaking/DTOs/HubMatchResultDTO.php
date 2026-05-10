<?php

namespace App\Domains\Matchmaking\DTOs;

class HubMatchResultDTO
{
    public function __construct(
        public readonly string $qdrantId,
        public readonly string $entityType,
        public readonly float $score,
        public readonly array $payload
    ) {}

    public static function fromQdrantPoint(array $point): self
    {
        return new self(
            qdrantId: $point['id'],
            entityType: $point['payload']['entity_type'] ?? 'unknown',
            score: $point['score'] ?? 0.0,
            payload: $point['payload'] ?? []
        );
    }
}
