<?php

namespace App\Domains\Community\Actions;

use App\Domains\Community\DTOs\GuildAccessDTO;
use App\Domains\Community\Events\GuildActivatedEvent;
use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildProfile;
use Illuminate\Support\Facades\Log;

class ValidateGuildAccessAction
{
    /**
     * Busca o registra la guild, verifica que esté activa y tenga cuota disponible.
     * Retorna un DTO con el resultado de acceso y la razón en caso de denegación.
     */
    public function execute(string $discordGuildId, string $discordUserId): GuildAccessDTO
    {
        Log::debug('[ValidateGuildAccessAction@execute] Inicio', [
            'discord_guild_id' => $discordGuildId,
            'discord_user_id'  => $discordUserId,
        ]);

        $guild = $this->findOrRegister($discordGuildId);

        if (! $guild->is_active) {
            Log::warning('[ValidateGuildAccessAction@execute] Guild inactiva — acceso denegado.', [
                'discord_guild_id' => $discordGuildId,
            ]);

            return new GuildAccessDTO($discordGuildId, $discordUserId, false, 'guild_inactive');
        }

        if (! $guild->hasQuotaAvailable()) {
            Log::warning('[ValidateGuildAccessAction@execute] Cuota de perfiles alcanzada.', [
                'discord_guild_id' => $discordGuildId,
                'quota'            => $guild->profile_quota,
            ]);

            return new GuildAccessDTO($discordGuildId, $discordUserId, false, 'quota_exceeded');
        }

        $this->ensureProfile($guild, $discordUserId);

        Log::info('[ValidateGuildAccessAction@execute] Acceso concedido.', [
            'guild_id'        => $guild->id,
            'discord_user_id' => $discordUserId,
        ]);

        return new GuildAccessDTO($discordGuildId, $discordUserId, true);
    }

    private function findOrRegister(string $discordGuildId): Guild
    {
        $guild = Guild::where('discord_guild_id', $discordGuildId)->with('archetypes')->first();

        if ($guild) {
            return $guild;
        }

        // Cross-domain: Archetype pertenece a Matchmaking — usar App\Models durante migración incremental.
        $defaultArchetype = \App\Models\Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto']
        );

        $guild = Guild::create([
            'discord_guild_id' => $discordGuildId,
            'is_active'        => true,
            'plan_tier'        => 1,
            'profile_quota'    => 50,
        ]);

        $guild->archetypes()->attach($defaultArchetype->id, ['is_primary' => true]);
        $guild->load('archetypes');

        GuildActivatedEvent::dispatch($guild);

        Log::info('[ValidateGuildAccessAction] Guild auto-registrada.', [
            'discord_guild_id' => $discordGuildId,
            'guild_id'         => $guild->id,
        ]);

        return $guild;
    }

    private function ensureProfile(Guild $guild, string $discordUserId): GuildProfile
    {
        return GuildProfile::firstOrCreate(
            ['guild_id' => $guild->id, 'discord_user_id' => $discordUserId],
            ['status' => 'active']
        );
    }
}
