<?php

namespace App\Domains\Community\Events;

use App\Domains\Community\Models\Player;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerRegisteredEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Player $player,
        public readonly string $discordGuildId,
    ) {
    }
}
