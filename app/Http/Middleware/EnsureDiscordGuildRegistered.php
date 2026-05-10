<?php

namespace App\Http\Middleware;

use App\Application\Services\GuildValidationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureDiscordGuildRegistered
{
    public function __construct(private readonly GuildValidationService $guildValidator) {}

    public function handle(Request $request, Closure $next): Response
    {
        // PING handshake — siempre pasar sin validación
        if ((int) $request->input('type') === 1) {
            return $next($request);
        }

        $discordGuildId = $request->input('guild_id');

        // DM sin guild_id — pasar sin validación de guild
        if (! $discordGuildId) {
            return $next($request);
        }

        Log::debug('[EnsureDiscordGuildRegistered@handle] Verificando guild', [
            'discord_guild_id' => $discordGuildId,
        ]);

        $guild = $this->guildValidator->findOrRegister($discordGuildId);

        if (! $this->guildValidator->assertActive($guild)) {
            Log::warning('[EnsureDiscordGuildRegistered@handle] Guild inactiva — bloqueando interacción', [
                'discord_guild_id' => $discordGuildId,
                'guild_id'         => $guild->id,
            ]);

            return response()->json([
                'type' => 4,
                'data' => [
                    'content' => '⛔ Este servidor no está activo en MUDRAIS. Contacta al administrador.',
                    'flags'   => 64,
                ],
            ]);
        }

        Log::debug('[EnsureDiscordGuildRegistered@handle] Guild válida, adjuntando al request', [
            'guild_id' => $guild->id,
        ]);

        $request->attributes->set('guild', $guild);

        return $next($request);
    }
}
