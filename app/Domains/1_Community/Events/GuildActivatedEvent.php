<?php

namespace App\Domains\Community\Events;

use App\Domains\Community\Models\Guild;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GuildActivatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Guild $guild,
    ) {
    }
}
