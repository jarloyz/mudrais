<?php

namespace App\Domains\Matchmaking\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayersMatchedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $playerIds    IDs de jugadores emparejados (discord_user_ids)
     * @param  string        $archetypeVectorName  Arquetipo que dio origen al match
     * @param  string        $discordGuildId  Servidor donde ocurrió la búsqueda
     */
    public function __construct(
        public readonly array $playerIds,
        public readonly string $archetypeVectorName,
        public readonly string $discordGuildId,
    ) {
    }
}
