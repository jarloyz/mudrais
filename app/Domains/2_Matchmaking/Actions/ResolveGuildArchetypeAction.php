<?php

namespace App\Domains\Matchmaking\Actions;

use App\Domains\Matchmaking\Models\Archetype;
use Illuminate\Support\Facades\Log;

class ResolveGuildArchetypeAction
{
    /**
     * Resuelve el Archetype a partir del discord_guild_id.
     * Si la guild existe y tiene archetype, lo devuelve.
     * Si no, devuelve el arquetipo por defecto (ttrpg_text_v1).
     */
    public function execute(string $discordGuildId): Archetype
    {
        Log::debug('[ResolveGuildArchetypeAction@execute] Inicio.', ['discord_guild_id' => $discordGuildId]);

        // Cross-domain: consultar Guild del dominio Community durante la migración incremental.
        $archetype = \App\Models\Guild::where('discord_guild_id', $discordGuildId)
            ->with('archetypes.prompts')
            ->first()
            ?->archetypes->first();

        if ($archetype) {
            Log::debug('[ResolveGuildArchetypeAction@execute] Arquetipo encontrado desde guild.', [
                'archetype_id'       => $archetype->id,
                'qdrant_vector_name' => $archetype->qdrant_vector_name,
            ]);

            return $archetype instanceof Archetype
                ? $archetype
                : Archetype::find($archetype->id);
        }

        $default = Archetype::with('prompts')
            ->where('qdrant_vector_name', 'ttrpg_text_v1')
            ->first()
            ?? Archetype::firstOrCreate(
                ['qdrant_vector_name' => 'ttrpg_text_v1'],
                ['name' => 'TTRPG Texto']
            )->load('prompts');

        Log::debug('[ResolveGuildArchetypeAction@execute] Usando arquetipo por defecto.', [
            'archetype_id'       => $default?->id,
            'qdrant_vector_name' => $default?->qdrant_vector_name,
        ]);

        return $default;
    }
}
