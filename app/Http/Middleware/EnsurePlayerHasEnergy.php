<?php

namespace App\Http\Middleware;

use App\Services\Discord\CommandEnergyCostService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlayerHasEnergy
{
    public function __construct(private readonly CommandEnergyCostService $energyService) {}

    public function handle(Request $request, Closure $next): Response
    {
        // PING — siempre pasar
        if ((int) $request->input('type') === 1) {
            return $next($request);
        }

        $player = $request->attributes->get('discord_player');
        $guild  = $request->attributes->get('guild');

        // Sin player o sin guild (DM, comando público, o tipo 3/5): sin verificación de energía
        if (! $player || ! $guild) {
            return $next($request);
        }

        $commandName = $request->input('data.name');

        Log::debug('[EnsurePlayerHasEnergy@handle] Verificando energía', [
            'player_id'     => $player->id,
            'command'       => $commandName,
            'energy_actual' => $player->energy,
        ]);

        $cost = $this->energyService->getCost($commandName, $guild);

        if ($cost === 0) {
            return $next($request);
        }

        if ($player->energy < $cost) {
            Log::warning('[EnsurePlayerHasEnergy@handle] Energía insuficiente', [
                'player_id'      => $player->id,
                'energy_actual'  => $player->energy,
                'cost_requerido' => $cost,
                'command'        => $commandName,
            ]);

            return response()->json([
                'type' => 4,
                'data' => [
                    'content' => __('discord.energy_insufficient', [
                        'cost'    => $cost,
                        'command' => $commandName,
                        'energy'  => $player->energy,
                    ]),
                    'flags' => 64,
                ],
            ]);
        }

        Log::info('[EnsurePlayerHasEnergy@handle] Energía suficiente', [
            'player_id' => $player->id,
            'command'   => $commandName,
            'cost'      => $cost,
        ]);

        return $next($request);
    }
}
