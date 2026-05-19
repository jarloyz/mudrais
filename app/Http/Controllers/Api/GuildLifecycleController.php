<?php

namespace App\Http\Controllers\Api;

use App\Domains\Community\Models\Guild;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\DiscordBot;
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
        $botConfig = $this->resolveBotBySecret($secret);

        if (! $botConfig) {
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
            $this->registerBotForGuild($guild, $botConfig);

            Log::info('[GuildLifecycleController@register] Guild registrada', [
                'guild_id'         => $guild->id,
                'discord_guild_id' => $guild->discord_guild_id,
                'bot_slug'         => $botConfig['slug'],
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

    /**
     * Busca en el mapa de bots aquel cuyo bot_token coincide con el secret.
     * Fallback al token legacy para mantener compatibilidad con el bot Alpha.
     */
    private function resolveBotBySecret(?string $secret): ?array
    {
        if ($secret === null) {
            return null;
        }

        foreach (config('services.discord.bots', []) as $appId => $bot) {
            if (isset($bot['bot_token']) && hash_equals((string) $bot['bot_token'], $secret)) {
                return array_merge($bot, ['app_id' => $appId]);
            }
        }

        return null;
    }

    /**
     * Crea o actualiza el registro en discord_bots y lo vincula al guild.
     */
    private function registerBotForGuild(Guild $guild, array $botConfig): void
    {
        $discordBot = DiscordBot::updateOrCreate(
            ['app_id' => $botConfig['app_id']],
            [
                'slug'      => $botConfig['slug'],
                'tier'      => $botConfig['tier'],
                'is_active' => true,
            ]
        );

        $guild->bots()->syncWithoutDetaching([
            $discordBot->id => ['installed_at' => now()],
        ]);

        Log::debug('[GuildLifecycleController] Bot vinculado al guild', [
            'guild_id'   => $guild->id,
            'bot_slug'   => $botConfig['slug'],
            'bot_app_id' => $botConfig['app_id'],
        ]);
    }
}
