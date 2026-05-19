<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildMember;
use App\Http\Controllers\Controller;
use App\Models\DiscordBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\Uuid;

class BotGammaInviteController extends Controller
{
    public function redirect(): RedirectResponse
    {
        Log::debug('[BotGammaInviteController@redirect] Generando URL de invitacion de bot gamma');

        if (! config('services.discord_gamma.bot_redirect')) {
            Log::error('[BotGammaInviteController@redirect] DISCORD_BOT_REDIRECT_URI_GAMMA no esta configurado en .env');
        }

        $url = 'https://discord.com/oauth2/authorize?' . http_build_query(array_filter([
            'client_id'     => config('services.discord_gamma.client_id'),
            'scope'         => 'bot applications.commands',
            'permissions'   => config('services.discord_gamma.bot_permissions', '0'),
            'response_type' => 'code',
            'redirect_uri'  => config('services.discord_gamma.bot_redirect'),
        ], fn ($v) => $v !== null && $v !== ''));

        Log::debug('[BotGammaInviteController@redirect] Redirigiendo a Discord', ['url' => $url]);

        return redirect($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        Log::debug('[BotGammaInviteController@callback] Inicio', [
            'code_present' => $request->has('code'),
            'guild_id'     => $request->query('guild_id'),
            'permissions'  => $request->query('permissions'),
            'error'        => $request->query('error'),
        ]);

        if ($request->has('error')) {
            Log::warning('[BotGammaInviteController@callback] Discord rechazo la instalacion del bot gamma', [
                'error'       => $request->query('error'),
                'description' => $request->query('error_description'),
            ]);

            return redirect()->route('filament.player.pages.discord-dashboard')
                ->with('error', 'La instalacion del bot de voz fue rechazada por Discord.');
        }

        $guildId = $request->query('guild_id');

        if (! $guildId) {
            Log::warning('[BotGammaInviteController@callback] guild_id ausente en el callback gamma');

            return redirect()->route('filament.player.pages.discord-dashboard')
                ->with('error', 'No se recibio el ID del servidor de Discord.');
        }

        /** @var \App\Models\Player $player */
        $player = Auth::guard('player_web')->user();

        try {
            DB::transaction(function () use ($guildId, $player, &$guild) {
                $guild = Guild::firstOrCreate(
                    ['discord_guild_id' => $guildId],
                    ['owner_discord_id' => $player->discord_id]
                );

                $gammaBot = DiscordBot::where('slug', 'gamma')->first();
                if ($gammaBot && ! DB::table('guild_bots')->where('guild_id', $guild->id)->where('discord_bot_id', $gammaBot->id)->exists()) {
                    DB::table('guild_bots')->insert([
                        'id'             => (string) Uuid::v7(),
                        'guild_id'       => $guild->id,
                        'discord_bot_id' => $gammaBot->id,
                        'installed_at'   => now(),
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                    Log::info('[BotGammaInviteController@callback] Bot gamma vinculado a guild', [
                        'guild_id' => $guild->id,
                        'bot_id'   => $gammaBot->id,
                    ]);
                }

                GuildMember::updateOrCreate(
                    ['player_id' => $player->id, 'guild_id' => $guild->id],
                    ['role' => 'admin']
                );

                Log::info('[BotGammaInviteController@callback] Membresia admin creada/confirmada (gamma)', [
                    'guild_id'  => $guild->id,
                    'player_id' => $player->id,
                ]);
            });

            Log::info('[BotGammaInviteController@callback] Bot gamma instalado en guild', [
                'guild_id'         => $guild->id,
                'discord_guild_id' => $guildId,
                'player_id'        => $player->id,
                'was_created'      => $guild->wasRecentlyCreated,
            ]);

            return redirect()->route('filament.player.pages.bot-success')
                ->with('guild_discord_id', $guildId)
                ->with('was_created', $guild->wasRecentlyCreated);
        } catch (\Exception $e) {
            Log::error('[BotGammaInviteController@callback] Error al registrar guild o asignar rol (gamma)', [
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
