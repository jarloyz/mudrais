<?php

namespace App\Domains\Narrative\Events;

use App\Domains\Narrative\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SceneCreatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Activity $scene,
        public readonly array $playerDiscordIds = [],
    ) {
    }
}
