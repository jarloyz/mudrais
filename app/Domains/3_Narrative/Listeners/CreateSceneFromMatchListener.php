<?php

namespace App\Domains\Narrative\Listeners;

use App\Domains\Matchmaking\Events\PlayersMatchedEvent;
use Illuminate\Support\Facades\Log;

class CreateSceneFromMatchListener
{
    /**
     * Escucha PlayersMatchedEvent del dominio Matchmaking.
     * Crea o prepara una Activity cuando hay jugadores emparejados.
     *
     * TODO: Implementar lógica de bootstrap de escena a partir de match.
     * Por ahora, solo loguea el evento para trazabilidad.
     */
    public function handle(PlayersMatchedEvent $event): void
    {
        Log::info('[CreateSceneFromMatchListener@handle] Jugadores emparejados — escena pendiente de inicializar.', [
            'player_ids'          => $event->playerIds,
            'archetype_vector'    => $event->archetypeVectorName,
            'discord_guild_id'    => $event->discordGuildId,
        ]);

        // TODO: Disparar BootstrapSceneAction con los playerIds y el arquetipo
    }
}
