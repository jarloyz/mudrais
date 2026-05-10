<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\PlayerRegistrationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPlayerRequest;
use Illuminate\Http\JsonResponse;

class PlayerController extends Controller
{
    public function register(RegisterPlayerRequest $request, PlayerRegistrationService $service): JsonResponse
    {
        $player = $service->register($request->validated());

        return response()->json([
            'player_id'  => $player->id,
            'discord_id' => $player->discord_id,
            'status'     => 'registered',
            'message'    => 'Tu perfil ha sido registrado. La indexación semántica se procesará en breves momentos.',
        ], 201);
    }
}
