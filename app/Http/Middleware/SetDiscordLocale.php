<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SetDiscordLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawLocale = (string) $request->input('locale', 'es-ES');
        $lang = str_starts_with($rawLocale, 'en') ? 'en' : 'es';

        Log::debug('[SetDiscordLocale@handle] Locale configurado', [
            'raw_locale' => $rawLocale,
            'lang'       => $lang,
        ]);

        App::setLocale($lang);

        return $next($request);
    }

    /**
     * Persiste el locale detectado en el player record para Jobs salientes.
     * Corre DESPUÉS de enviar la respuesta HTTP, sin bloquear el tiempo de respuesta.
     */
    public function terminate(Request $request, Response $response): void
    {
        $player = $request->attributes->get('discord_player');
        if (! $player) {
            return;
        }

        $lang = App::getLocale();
        if ($player->preferred_locale !== $lang) {
            $player->update(['preferred_locale' => $lang]);
            Log::debug('[SetDiscordLocale@terminate] preferred_locale persistido', [
                'player_id' => $player->id,
                'locale'    => $lang,
            ]);
        }
    }
}
