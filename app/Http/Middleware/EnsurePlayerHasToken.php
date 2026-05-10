<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlayerHasToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::guard('sanctum')->check()) {
            abort(401, 'Unauthenticated');
        }

        $player = Auth::guard('sanctum')->user();
        Log::info('[EnsurePlayerHasToken] Player authenticated successfully.', ['player_id' => $player->id]);

        return $next($request);
    }
}
