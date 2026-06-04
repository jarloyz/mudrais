<?php

namespace App\Application\Services;

use App\Domains\Matchmaking\Models\Archetype;
use App\Models\AppSetting;
use App\Models\Guild;
use App\Models\GuildProfile;
use Illuminate\Support\Facades\Log;

class GuildValidationService
{
    /**
     * Busca o registra una guild con valores por defecto.
     * Auto-registra guilds desconocidas con arquetipo TTRPG Texto y tier=1.
     */
    public function findOrRegister(string $discordGuildId): Guild
    {
        Log::debug('[GuildValidationService@findOrRegister] Inicio.', ['discord_guild_id' => $discordGuildId]);

        $guild = Guild::where('discord_guild_id', $discordGuildId)->with('archetypes')->first();

        if ($guild) {
            Log::debug('[GuildValidationService@findOrRegister] Guild existente encontrada.', [
                'guild_id'  => $guild->id,
                'is_active' => $guild->is_active,
                'archetype' => $guild->archetypes->first()->qdrant_vector_name ?? null,
            ]);

            return $guild;
        }

        $defaultArchetype = Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto']
        );

        $guild = Guild::create([
            'discord_guild_id' => $discordGuildId,
            'is_active'        => true,
            'is_bot_allowed'   => AppSetting::bool('guild_bot_allowed_default', true),
            'plan_tier'        => 1,
            'profile_quota'    => 50,
        ]);

        $guild->archetypes()->attach($defaultArchetype->id, ['is_primary' => true]);
        $guild->load('archetypes');

        Log::info('[GuildValidationService@findOrRegister] Guild auto-registrada.', [
            'discord_guild_id' => $discordGuildId,
            'guild_id'         => $guild->id,
            'archetype'        => $guild->archetypes->first()->qdrant_vector_name ?? null,
        ]);

        return $guild;
    }

    /**
     * Verifica que la guild esté activa.
     * Retorna false y loguea si no lo está.
     */
    public function assertActive(Guild $guild): bool
    {
        Log::debug('[GuildValidationService@assertActive] Evaluando.', [
            'guild_id'  => $guild->id,
            'is_active' => $guild->is_active,
        ]);

        if (!$guild->is_active) {
            Log::warning('[GuildValidationService@assertActive] Guild inactiva — interacción bloqueada.', [
                'discord_guild_id' => $guild->discord_guild_id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Verifica que la guild tenga cuota disponible para nuevos perfiles.
     */
    public function assertWithinQuota(Guild $guild): bool
    {
        $active = $guild->activeProfileCount();
        $within = $active < $guild->profile_quota;

        Log::debug('[GuildValidationService@assertWithinQuota] Evaluando cuota.', [
            'guild_id'      => $guild->id,
            'active_count'  => $active,
            'quota'         => $guild->profile_quota,
            'within_quota'  => $within,
        ]);

        if (!$within) {
            Log::warning('[GuildValidationService@assertWithinQuota] Cuota alcanzada.', [
                'discord_guild_id' => $guild->discord_guild_id,
                'active_count'     => $active,
                'quota'            => $guild->profile_quota,
            ]);
        }

        return $within;
    }

    /**
     * Registra o actualiza la membresía de un usuario en la guild.
     */
    public function ensureGuildProfile(Guild $guild, string $discordUserId): GuildProfile
    {
        return GuildProfile::firstOrCreate(
            ['guild_id' => $guild->id, 'discord_user_id' => $discordUserId],
            ['status' => 'active']
        );
    }
}
