<?php

namespace App\Services\Auth;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildMember;
use App\Domains\Community\Models\Player;
use Illuminate\Support\Facades\Log;

class GuildMembershipService
{
    /**
     * Une a un jugador a una guild con un rol específico.
     *
     * @param Player $player
     * @param Guild $guild
     * @param string $role 'admin', 'moderator', 'player'
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function joinGuild(Player $player, Guild $guild, string $role = 'player'): \Illuminate\Database\Eloquent\Model
    {
        Log::debug('[GuildMembershipService@joinGuild] Iniciando unión de guild', [
            'player_id'        => $player->id,
            'discord_guild_id' => $guild->discord_guild_id,
            'role'             => $role,
        ]);

        $member = GuildMember::updateOrCreate(
            ['player_id' => $player->id, 'guild_id' => $guild->id],
            ['role' => $role]
        );

        Log::info('[GuildMembershipService@joinGuild] Jugador unido exitosamente a la guild', [
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
            'role'      => $role,
        ]);

        return $member;
    }

    /**
     * Promueve (o degrada) a un jugador a un nuevo rol dentro de una guild.
     *
     * @param Player $player
     * @param Guild $guild
     * @param string $newRole 'admin', 'moderator', 'player'
     * @return GuildMember
     * @throws \InvalidArgumentException Si el rol no es válido o el jugador no pertenece a la guild.
     */
    public function promotePlayer(Player $player, Guild $guild, string $newRole): GuildMember
    {
        if (!in_array($newRole, ['admin', 'moderator', 'player'])) {
            Log::error('[GuildMembershipService@promotePlayer] Rol inválido provisto', [
                'player_id' => $player->id,
                'guild_id'  => $guild->id,
                'newRole'   => $newRole,
            ]);
            throw new \InvalidArgumentException("Rol inválido: {$newRole}");
        }

        $member = GuildMember::where([
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
        ])->first();

        if (!$member) {
            Log::error('[GuildMembershipService@promotePlayer] El jugador no pertenece a la guild', [
                'player_id' => $player->id,
                'guild_id'  => $guild->id,
            ]);
            throw new \InvalidArgumentException("El jugador no pertenece a esta guild.");
        }

        $oldRole = $member->role;
        $member->update(['role' => $newRole]);

        Log::info('[GuildMembershipService@promotePlayer] Rol de jugador actualizado', [
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
            'old_role'  => $oldRole,
            'new_role'  => $newRole,
        ]);

        return $member;
    }

    /**
     * Resuelve y asigna el rol de admin al propietario de la guild, si ya existe como Player en la base de datos.
     *
     * @param Guild $guild
     * @return void
     */
    public function resolveOwnerRole(Guild $guild): void
    {
        if (!$guild->owner_discord_id) {
            Log::warning('[GuildMembershipService@resolveOwnerRole] La guild no tiene owner_discord_id configurado', [
                'guild_id' => $guild->id,
            ]);
            return;
        }

        $player = Player::where('discord_id', $guild->owner_discord_id)->first();

        if ($player) {
            $this->joinGuild($player, $guild, 'admin');
            Log::info('[GuildMembershipService@resolveOwnerRole] Rol admin asignado al owner de la guild', [
                'guild_id'  => $guild->id,
                'player_id' => $player->id,
            ]);
        } else {
            Log::debug('[GuildMembershipService@resolveOwnerRole] El owner de la guild aún no está registrado como Player', [
                'guild_id'         => $guild->id,
                'owner_discord_id' => $guild->owner_discord_id,
            ]);
        }
    }

    /**
     * Obtiene la membresía de un jugador en una guild o la crea si no existe.
     * También promueve a admin si resulta ser el owner y no tenía el rol.
     *
     * @param Player $player
     * @param Guild $guild
     * @return GuildMember
     */
    public function getOrAssign(Player $player, Guild $guild): GuildMember
    {
        $member = GuildMember::where([
            'player_id' => $player->id,
            'guild_id'  => $guild->id,
        ])->first();

        if (!$member) {
            $member = $this->joinGuild($player, $guild, 'player');
        }

        if ($guild->owner_discord_id === $player->discord_id && $member->role !== 'admin') {
            $member = $this->promotePlayer($player, $guild, 'admin');
        }

        return $member;
    }
}
