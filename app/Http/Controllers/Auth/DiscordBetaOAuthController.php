<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\DiscordOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class DiscordBetaOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        Log::debug('[DiscordBetaOAuthController@redirect] Iniciando redirect OAuth beta');

        $driver = Socialite::driver('discord-beta');
        if (config('services.discord_beta.stateless')) {
            $driver->stateless();
        }

        return $driver->scopes(['identify', 'email'])->redirect();
    }

    public function callback(DiscordOAuthService $service): RedirectResponse
    {
        Log::debug('[DiscordBetaOAuthController@callback] Inicio', [
            'code_present'  => request()->has('code'),
            'state_present' => request()->has('state'),
            'error'         => request()->query('error'),
        ]);

        try {
            $driver = Socialite::driver('discord-beta');
            if (config('services.discord_beta.stateless')) {
                $driver->stateless();
            }

            $socialUser = $driver->user();
            $player     = $service->authenticateOrRegister($socialUser);

            Auth::guard('player_web')->login($player);

            Log::info('[DiscordBetaOAuthController@callback] Player autenticado via sesion web (beta)', [
                'player_id'  => $player->id,
                'discord_id' => $player->discord_id,
            ]);

            return redirect()->route('filament.player.pages.discord-dashboard');
        } catch (\Exception $e) {
            Log::error('[DiscordBetaOAuthController@callback] Fallo de autenticacion', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return redirect()->route('discord.login.error')
                ->with('error', 'Autenticacion con Discord (beta) fallida. Intenta nuevamente.');
        }
    }
}
