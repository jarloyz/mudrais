<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Community\Models\Guild;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BotInviteController extends Controller
{
    public function redirect(): RedirectResponse
    {
        Log::debug('[BotInviteController@redirect] Generando URL de invitacion de bot');

        if (! config('services.discord.bot_redirect')) {
            Log::error('[BotInviteController@redirect] DISCORD_BOT_REDIRECT_URI no esta configurado en .env');
        }

        $url = 'https://discord.com/oauth2/authorize?' . http_build_query(array_filter([
            'client_id'     => config('services.discord.client_id'),
            'scope'         => 'bot applications.commands',
            'permissions'   => config('services.discord.bot_permissions', '0'),
            'response_type' => 'code',
            'redirect_uri'  => config('services.discord.bot_redirect'),
        ], fn ($v) => $v !== null && $v !== ''));

        Log::debug('[BotInviteController@redirect] Redirigiendo a Discord', ['url' => $url]);

        return redirect($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        Log::debug('[BotInviteController@callback] Inicio', [
            'code_present' => $request->has('code'),
            'guild_id'     => $request->query('guild_id'),
            'permissions'  => $request->query('permissions'),
            'error'        => $request->query('error'),
        ]);

        if ($request->has('error')) {
            Log::warning('[BotInviteController@callback] Discord rechazo la instalacion del bot', [
                'error'       => $request->query('error'),
                'description' => $request->query('error_description'),
            ]);

            return redirect()->route('filament.player.pages.discord-dashboard')
                ->with('error', 'La instalacion del bot fue rechazada por Discord.');
        }

        $guildId = $request->query('guild_id');

        if (! $guildId) {
            Log::warning('[BotInviteController@callback] guild_id ausente en el callback');

            return redirect()->route('filament.player.pages.discord-dashboard')
                ->with('error', 'No se recibio el ID del servidor de Discord.');
        }

        /** @var \App\Domains\Community\Models\Player $player */
        $player = Auth::guard('player_web')->user();

        try {
            $guild = Guild::firstOrCreate(
                ['discord_guild_id' => $guildId],
                ['owner_discord_id' => $player->discord_id]
            );

            Log::info('[BotInviteController@callback] Bot instalado en guild', [
                'guild_id'         => $guild->id,
                'discord_guild_id' => $guildId,
                'player_id'        => $player->id,
                'was_created'      => $guild->wasRecentlyCreated,
            ]);

            return redirect()->route('filament.player.pages.bot-success')
                ->with('guild_discord_id', $guildId)
                ->with('was_created', $guild->wasRecentlyCreated);
        } catch (\Exception $e) {
            Log::error('[BotInviteController@callback] Error al registrar guild', [
                'guild_id'  => $guildId,
                'player_id' => $player->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return redirect()->route('filament.player.pages.discord-dashboard')
                ->with('error', 'Error interno al registrar el servidor.');
        }
    }
}
