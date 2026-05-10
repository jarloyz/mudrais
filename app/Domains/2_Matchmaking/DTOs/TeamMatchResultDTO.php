<?php

namespace App\Domains\Matchmaking\DTOs;

use App\Domains\Narrative\Models\Activity;

readonly class TeamMatchResultDTO
{
    /**
     * @param SlotMatchResultDTO[] $slots
     */
    public function __construct(
        public Activity $parent,
        public array    $slots,
    ) {}

    public function filledSlots(): int
    {
        return collect($this->slots)->filter(fn(SlotMatchResultDTO $s) => $s->hasCandidates())->count();
    }

    public function totalSlots(): int
    {
        return $this->parent->required_slots ?? count($this->slots);
    }

    public function isComplete(): bool
    {
        return $this->filledSlots() >= $this->totalSlots();
    }
}
