<?php

namespace App\Http\Controllers\Api;

use App\Domains\Community\Models\Guild;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Services\Auth\GuildMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GuildLifecycleController extends Controller
{
    public function __construct(private readonly GuildMembershipService $membershipService) {}

    public function register(Request $request): JsonResponse
    {
        Log::debug('[GuildLifecycleController@register] Inicio', [
            'discord_guild_id' => $request->input('discord_guild_id'),
            'owner_discord_id' => $request->input('owner_discord_id'),
        ]);

        $secret = $request->header('X-Bot-Secret');
        if ($secret !== config('services.discord.bot_token')) {
            Log::warning('[GuildLifecycleController@register] X-Bot-Secret inválido');
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'discord_guild_id' => ['required', 'string', 'max:30'],
            'owner_discord_id' => ['required', 'string', 'max:30'],
        ]);

        try {
            $defaultArchetype = Archetype::firstOrCreate(
                ['qdrant_vector_name' => 'ttrpg_text_v1'],
                ['name' => 'TTRPG Texto']
            );

            $guild = Guild::updateOrCreate(
                ['discord_guild_id' => $validated['discord_guild_id']],
                ['owner_discord_id' => $validated['owner_discord_id']]
            );

            $guild->archetypes()->syncWithoutDetaching([
                $defaultArchetype->id => ['is_primary' => true],
            ]);

            $this->membershipService->resolveOwnerRole($guild);

            Log::info('[GuildLifecycleController@register] Guild registrada', [
                'guild_id'         => $guild->id,
                'discord_guild_id' => $guild->discord_guild_id,
            ]);

            return response()->json(['status' => 'ok', 'guild_id' => $guild->id]);
        } catch (\Exception $e) {
            Log::error('[GuildLifecycleController@register] Excepción al registrar guild', [
                'discord_guild_id' => $validated['discord_guild_id'],
                'message'          => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Error interno'], 500);
        }
    }
}
