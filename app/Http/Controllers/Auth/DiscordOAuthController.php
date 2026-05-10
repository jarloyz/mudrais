<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\DiscordOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class DiscordOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        Log::debug('[DiscordOAuthController@redirect] Iniciando redirect OAuth');

        $driver = Socialite::driver('discord');
        if (config('services.discord.stateless')) {
            $driver->stateless();
        }

        return $driver->scopes(['identify', 'email'])->redirect();
    }

    public function callback(DiscordOAuthService $service): RedirectResponse
    {
        Log::debug('[DiscordOAuthController@callback] Inicio', [
            'code_present'  => request()->has('code'),
            'state_present' => request()->has('state'),
            'error'         => request()->query('error'),
        ]);

        try {
            $driver = Socialite::driver('discord');
            if (config('services.discord.stateless')) {
                $driver->stateless();
            }

            $socialUser = $driver->user();
            $player     = $service->authenticateOrRegister($socialUser);

            Auth::guard('player_web')->login($player);

            Log::info('[DiscordOAuthController@callback] Player autenticado via sesion web', [
                'player_id'  => $player->id,
                'discord_id' => $player->discord_id,
            ]);

            return redirect()->route('filament.player.pages.discord-dashboard');
        } catch (\Exception $e) {
            Log::error('[DiscordOAuthController@callback] Fallo de autenticacion', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return redirect()->route('discord.login.error')
                ->with('error', 'Autenticacion con Discord fallida. Intenta nuevamente.');
        }
    }
}
