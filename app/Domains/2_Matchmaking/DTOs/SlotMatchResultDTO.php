<?php

namespace App\Domains\Matchmaking\DTOs;

readonly class SlotMatchResultDTO
{
    /**
     * @param HubMatchResultDTO[] $candidates
     */
    public function __construct(
        public string $slotActivityId,
        public string $slotTitle,
        public array  $candidates,
    ) {}

    public function hasCandidates(): bool
    {
        return count($this->candidates) > 0;
    }

    public function topCandidate(): ?HubMatchResultDTO
    {
        return $this->candidates[0] ?? null;
    }
}
