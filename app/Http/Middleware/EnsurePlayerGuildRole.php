<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlayerGuildRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $discordGuildId = $request->input('guild_id') ?? $request->header('X-Guild-Id');

        Log::debug('[EnsurePlayerGuildRole@handle] Verificando rol de guild', [
            'roles_requeridos' => $roles,
            'discord_guild_id' => $discordGuildId,
        ]);

        $player = Auth::guard('sanctum')->user();

        if (! $player) {
            Log::warning('[EnsurePlayerGuildRole@handle] Player no autenticado');
            abort(401);
        }

        if (! $discordGuildId) {
            Log::warning('[EnsurePlayerGuildRole@handle] guild_id ausente en el request', [
                'player_id' => $player->id,
            ]);
            abort(400, 'guild_id requerido');
        }

        $role = $player->getRoleIn($discordGuildId);

        if (! in_array($role, $roles)) {
            Log::warning('[EnsurePlayerGuildRole@handle] Permiso de guild insuficiente', [
                'player_id'        => $player->id,
                'discord_guild_id' => $discordGuildId,
                'role_actual'      => $role,
                'roles_requeridos' => $roles,
            ]);
            abort(403);
        }

        Log::info('[EnsurePlayerGuildRole@handle] Acceso concedido', [
            'player_id'        => $player->id,
            'discord_guild_id' => $discordGuildId,
            'role'             => $role,
        ]);

        return $next($request);
    }
}
