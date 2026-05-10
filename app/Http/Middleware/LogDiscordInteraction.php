<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogDiscordInteraction
{
    /**
     * Intercepta y loguea la respuesta JSON enviada a Discord.
     * Útil para auditoría de interacciones y validación de costos futuros.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $interaction = $request->json()->all();

            $discordId = $interaction['member']['user']['id'] ?? $interaction['user']['id'] ?? 'unknown';
            $customId  = $interaction['data']['custom_id'] ?? $interaction['data']['name'] ?? 'command';
            $type      = $data['type'] ?? 'unknown';

            Log::channel('discord')->info('Discord Interaction Response', [
                'discord_user_id' => $discordId,
                'interaction_id'  => $interaction['id'] ?? null,
                'custom_id'       => $customId,
                'response_type'   => $type,
                'payload_type'    => $data['type'] ?? null,
                'request_token'   => isset($interaction['token'])
                    ? substr($interaction['token'], 0, 8) . '...'
                    : null,
                'payload'         => $data,
            ]);
        }

        return $response;
    }
}
