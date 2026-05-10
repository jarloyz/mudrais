<?php

namespace App\Application\Services;

use App\Domains\Matchmaking\Models\Archetype;
use App\Models\Guild;
use Illuminate\Support\Facades\Log;

class ArchetypeResolverService
{
    /**
     * Resuelve el Archetype a partir del discord_guild_id.
     * Devuelve el arquetipo primario de la guild, o el por defecto si no tiene.
     */
    public function resolveFromGuild(string $discordGuildId): Archetype
    {
        Log::debug('[ArchetypeResolverService@resolveFromGuild] Inicio.', ['discord_guild_id' => $discordGuildId]);

        $archetype = Guild::where('discord_guild_id', $discordGuildId)
            ->with('archetypes.prompts')
            ->first()
            ?->archetypes->first();

        if ($archetype) {
            Log::debug('[ArchetypeResolverService@resolveFromGuild] Arquetipo encontrado desde guild.', [
                'archetype_id'        => $archetype->id,
                'qdrant_vector_name'  => $archetype->qdrant_vector_name,
            ]);

            return $archetype;
        }

        return $this->getDefaultArchetype();
    }

    /**
     * Resuelve el Archetype directamente desde un objeto Guild ya cargado.
     * Devuelve el arquetipo primario (primer elemento con is_primary=true).
     */
    public function resolveFromGuildModel(Guild $guild): Archetype
    {
        if (! $guild->relationLoaded('archetypes')) {
            $guild->load('archetypes.prompts');
        }

        $archetype = $guild->archetypes->first();

        if (! $archetype) {
            Log::debug('[ArchetypeResolverService@resolveFromGuildModel] Guild sin arquetipos, usando defecto.', [
                'guild_id' => $guild->id,
            ]);

            return $this->getDefaultArchetype();
        }

        if (! $archetype->relationLoaded('prompts')) {
            $archetype->load('prompts');
        }

        Log::debug('[ArchetypeResolverService@resolveFromGuildModel] Arquetipo resuelto.', [
            'guild_id'           => $guild->id,
            'archetype_id'       => $archetype->id,
            'qdrant_vector_name' => $archetype->qdrant_vector_name,
        ]);

        return $archetype;
    }

    private function getDefaultArchetype(): Archetype
    {
        $default = Archetype::with('prompts')
            ->where('qdrant_vector_name', 'ttrpg_text_v1')
            ->first()
            ?? Archetype::firstOrCreate(
                ['qdrant_vector_name' => 'ttrpg_text_v1'],
                ['name' => 'TTRPG Texto']
            )->load('prompts');

        Log::debug('[ArchetypeResolverService] Usando arquetipo por defecto.', [
            'archetype_id'       => $default?->id,
            'qdrant_vector_name' => $default?->qdrant_vector_name,
        ]);

        return $default;
    }
}
