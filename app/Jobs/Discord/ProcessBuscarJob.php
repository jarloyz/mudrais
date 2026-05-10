<?php

namespace App\Jobs\Discord;

use App\Application\Services\ArchetypeResolverService;
use App\Application\Services\QdrantService;
use App\Models\Player;
use App\Models\PlayerArchetypeProfile;
use App\Services\MatchmakingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBuscarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string  $token,
        public readonly string  $discordId,
        public readonly ?string $guildId = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(
        MatchmakingService $matchmaking,
        QdrantService $qdrant,
        ArchetypeResolverService $archetypeResolver,
    ): void {
        Log::debug('[ProcessBuscarJob] Buscando player', [
            'discord_id' => $this->discordId,
            'guild_id'   => $this->guildId,
        ]);

        $player = Player::where('discord_id', $this->discordId)->first();

        if (! $player) {
            Log::warning('[ProcessBuscarJob] Player no encontrado', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, 'No se pudo identificar tu perfil. Usa `/registro` primero.');
            return;
        }

        // ── B2B: resolver arquetipo y buscar en mudrais_profiles ─────────────
        $results = null;

        if ($this->guildId) {
            $archetype        = $archetypeResolver->resolveFromGuild($this->guildId);
            $archetypeProfile = PlayerArchetypeProfile::where('discord_user_id', $this->discordId)
                ->where('archetype_id', $archetype->id)
                ->first();

            if ($archetypeProfile && $archetypeProfile->qdrant_id) {
                $archetypeVector = $qdrant->getArchetypeProfileVector(
                    $archetypeProfile->qdrant_id,
                    $archetype->qdrant_vector_name
                );

                if (!empty($archetypeVector)) {
                    $userRedLines = $archetypeProfile->red_lines ?? [];

                    Log::info('[ProcessBuscarJob] Usando búsqueda B2B en mudrais_profiles.', [
                        'discord_id'   => $this->discordId,
                        'archetype'    => $archetype->qdrant_vector_name,
                        'red_lines'    => count($userRedLines),
                    ]);

                    $rawResults = $qdrant->searchArchetypeProfiles(
                        $archetype->qdrant_vector_name,
                        $archetypeVector,
                        $this->guildId,
                        $archetype->id,
                        $userRedLines,
                        10,
                    );

                    $results = $matchmaking->formatArchetypeResults($player, $rawResults);
                }
            }
        }

        // ── Fallback: búsqueda legacy en players_profiles ────────────────────
        if ($results === null) {
            Log::debug('[ProcessBuscarJob] Usando búsqueda legacy en players_profiles.', [
                'player_id' => $player->id,
            ]);

            $vector = $qdrant->getPlayerVector($player->id);

            if (empty($vector)) {
                Log::info('[ProcessBuscarJob] Sin vector legacy, omitiendo búsqueda legacy', ['player_id' => $player->id]);
                $results = [];
            } else {
                $results = $matchmaking->findPartnership($player, $vector, $this->guildId);
            }
        }

        Log::info('[ProcessBuscarJob] Ejecutando matchmaking', [
            'player_id' => $player->id,
            'guild_id'  => $this->guildId,
        ]);

        if (empty($results)) {
            Log::info('[ProcessBuscarJob] Sin resultados', ['player_id' => $player->id]);
            $this->sendFollowUp($this->token, 'No se encontraron partners compatibles en este servidor todavía.');
            return;
        }

        $top5 = array_slice($results, 0, 5);

        $embed = [
            'title'       => '🎯 Matchmaker: Top ' . count($top5) . ' Compañeros Compatibles',
            'color'       => hexdec('5865F2'),
            'description' => 'Hemos analizado la base de datos. Estos son los perfiles que mejor encajan con tu vibra:',
            'fields'      => [],
            'footer'      => ['text' => 'MUDRAIS Matchmaking System'],
        ];

        foreach ($top5 as $index => $match) {
            $posicion = $index + 1;
            $score    = round((float) $match['final_score'], 1);
            $bonus    = ($match['bonus_points'] ?? 0) > 0   ? " +{$match['bonus_points']}pts"  : '';
            $penalty  = ($match['penalty_points'] ?? 0) > 0 ? " −{$match['penalty_points']}pts" : '';
            $flag     = ! empty($match['country_code'])      ? " :flag_{$match['country_code']}:" : '';
            $sched    = ! empty($match['schedule_raw'])      ? "\n🕐 {$match['schedule_raw']}"   : '';
            $about    = ! empty($match['about_me'])
                ? "\n💬 " . mb_strimwidth($match['about_me'], 0, 100, '…')
                : '';
            $warnings = ! empty($match['warnings'])
                ? "\n⚠️ " . implode(' | ', $match['warnings'])
                : '';

            $tags = empty($match['shared_tags'])
                ? '*Ninguna específica*'
                : '`' . implode('` `', $match['shared_tags']) . '`';

            $embed['fields'][] = [
                'name'   => "#{$posicion} — 👤 {$match['username']}{$flag} (Score: {$score}{$bonus}{$penalty})",
                'value'  => "🏷️ **En común:** {$tags}{$sched}{$about}{$warnings}",
                'inline' => false,
            ];
        }

        Log::info('[ProcessBuscarJob] Resultados enviados como embed', [
            'player_id' => $player->id,
            'count'     => count($top5),
        ]);
        $this->sendFollowUp($this->token, '', ['embeds' => [$embed]]);
    }
}
