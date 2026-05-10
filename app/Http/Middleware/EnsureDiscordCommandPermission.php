<?php

namespace App\Http\Middleware;

use App\Domains\Community\Models\Player;
use App\Services\Auth\GuildMembershipService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureDiscordCommandPermission
{
    public function __construct(private readonly GuildMembershipService $membershipService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $interactionType = (int) $request->input('type');

        // PING — siempre pasar
        if ($interactionType === 1) {
            return $next($request);
        }

        // Componentes (type 3) y modals (type 5): el flujo fue iniciado por un slash command
        // que ya validó permisos. Pasar sin re-verificar.
        if (in_array($interactionType, [3, 5])) {
            return $next($request);
        }

        $commandName    = $request->input('data.name');
        $discordUserId  = $request->input('member.user.id') ?? $request->input('user.id');
        $discordGuildId = $request->input('guild_id');

        Log::debug('[EnsureDiscordCommandPermission@handle] Verificando permisos de comando', [
            'command'          => $commandName,
            'discord_user_id'  => $discordUserId,
            'discord_guild_id' => $discordGuildId,
        ]);

        // Sin guild (DM) — sin restricción de rol
        if (! $discordGuildId) {
            return $next($request);
        }

        $player = Player::where('discord_id', $discordUserId)->first();

        if (! $player) {
            // Comandos públicos (como /registro) permiten acceso sin Player registrado
            $publicCommands = config('historia.discord_public_commands', ['registro']);
            if (in_array($commandName, $publicCommands)) {
                Log::debug('[EnsureDiscordCommandPermission@handle] Comando público — pasando sin player', [
                    'command' => $commandName,
                ]);
                return $next($request);
            }

            Log::warning('[EnsureDiscordCommandPermission@handle] Player no encontrado', [
                'discord_user_id' => $discordUserId,
                'command'         => $commandName,
            ]);

            return response()->json([
                'type' => 4,
                'data' => [
                    'content' => '⚠️ No estás registrado en MUDRAIS. Usa `/registro` para comenzar.',
                    'flags'   => 64,
                ],
            ]);
        }

        // Auto-crear membresía en la guild si es la primera vez que el player interactúa
        $guild = $request->attributes->get('guild');
        if ($guild) {
            $this->membershipService->getOrAssign($player, $guild);
        }

        $requiredRoles = config('historia.discord_command_permissions.' . $commandName, ['admin', 'moderator', 'player']);
        $playerRole    = $player->getRoleIn($discordGuildId);

        if (! $playerRole || ! in_array($playerRole, $requiredRoles)) {
            Log::warning('[EnsureDiscordCommandPermission@handle] Permiso insuficiente', [
                'player_id'       => $player->id,
                'command'         => $commandName,
                'player_role'     => $playerRole,
                'required_roles'  => $requiredRoles,
            ]);

            return response()->json([
                'type' => 4,
                'data' => [
                    'content' => "🚫 No tienes permiso para usar `/{$commandName}` en este servidor.",
                    'flags'   => 64,
                ],
            ]);
        }

        // --- INICIO VERIFICACIÓN DE ARQUETIPO Y REGISTRO ---
        $commandsExcludedFromArchetypeCheck = ['create_vault', 'registro', 'search', 'buscar-partner'];
        $channelId = $request->input('channel_id');
        if ($channelId && ! in_array($commandName, $commandsExcludedFromArchetypeCheck)) {
            $vault = \App\Domains\Narrative\Models\Vault::with('archetypes')->where('discord_channel_id', (string) $channelId)->first();
            if ($vault) {
                $archetype = $vault->primaryArchetype();
                if ($archetype) {
                    $profileExists = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::where('player_id', $player->id)
                        ->where('archetype_id', $archetype->id)
                        ->exists();

                    if (! $profileExists) {
                        Log::warning('[EnsureDiscordCommandPermission@handle] Player no registrado en arquetipo del canal', [
                            'player_id'    => $player->id,
                            'archetype_id' => $archetype->id,
                        ]);

                        return response()->json([
                            'type' => 4,
                            'data' => [
                                'content' => '',
                                'flags'   => 64,
                                'embeds'  => [[
                                    'title'       => 'Registro Requerido',
                                    'description' => "No estás registrado en el arquetipo **{$archetype->name}**.\nDebes registrar tu ficha para interactuar en este canal.",
                                    'color'       => 0xFF0000,
                                ]],
                                'components' => [[
                                    'type' => 1,
                                    'components' => [[
                                        'type'      => 2,
                                        'style'     => 1,
                                        'label'     => '📝 Registrarse',
                                        'custom_id' => "btn_abrir_modal_1_nuevo:{$archetype->id}",
                                    ]],
                                ]],
                            ],
                        ]);
                    }
                }
            }
        }
        // --- FIN VERIFICACIÓN DE ARQUETIPO Y REGISTRO ---

        Log::info('[EnsureDiscordCommandPermission@handle] Permiso concedido', [
            'player_id' => $player->id,
            'command'   => $commandName,
            'role'      => $playerRole,
        ]);

        $request->attributes->set('discord_player', $player);

        return $next($request);
    }
}
