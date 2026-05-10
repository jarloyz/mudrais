<?php

namespace App\Jobs\Discord;

use App\Application\Services\ArchetypeResolverService;
use App\Application\Services\QdrantService;
use App\Models\Player;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Narrative\Models\Vault;
use App\Services\MatchmakingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(
        public readonly string  $token,
        public readonly string  $discordId,
        public readonly ?string $guildId = null,
        public readonly ?string $channelId = null,
        public readonly string  $objetivo = 'partner',
        public readonly ?string $texto = null,
        public readonly ?string $periodo = null,
    ) {
        $this->onQueue('high');
    }

    public function handle(
        MatchmakingService $matchmaking,
        QdrantService $qdrant,
        ArchetypeResolverService $archetypeResolver,
        \App\Application\Contracts\EmbeddingGateway $gateway,
        \App\Infrastructure\Ai\Agents\ContextOptimizerAgent $contextOptimizer,
        \App\Domains\Matchmaking\Services\EntityTypePromptBuilderService $promptBuilder
    ): void {
        Log::debug('[ProcessSearchJob] Iniciando búsqueda universal', [
            'discord_id' => $this->discordId,
            'objetivo'   => $this->objetivo,
            'texto'      => $this->texto,
        ]);

        if ($this->objetivo === 'partner' || $this->objetivo === 'jugador') {
            $player = Player::where('discord_id', $this->discordId)->first();
            if (! $player) {
                $this->sendFollowUp($this->token, 'No se pudo identificar tu perfil. Usa `/registro` primero.');
                return;
            }

            $vault     = $this->channelId ? Vault::where('discord_channel_id', $this->channelId)->first() : null;
            $archetype = $vault?->primaryArchetype() ?? $archetypeResolver->resolveFromGuild($this->guildId);
            if (! $archetype) {
                $this->sendFollowUp($this->token, '⚠️ No se pudo determinar el arquetipo de este servidor.');
                return;
            }

            $archetypeProfile = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::where('player_id', $player->id)
                ->where('archetype_id', $archetype->id)
                ->first();

            $queryVector = $archetypeProfile?->player_style_vector ?: [];

            if (!empty($this->texto)) {
                try {
                    $optimized   = $contextOptimizer->optimize($this->texto, $player->id);
                    $textToEmbed = $optimized['optimized_text_en'] ?? $this->texto;
                    Log::info('[ProcessSearchJob] Texto partner optimizado', [
                        'original'         => $this->texto,
                        'optimized_length' => strlen($textToEmbed),
                    ]);

                    $model      = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');
                    $textVector = $gateway->embed($model, $textToEmbed);

                    if (!empty($textVector)) {
                        $queryVector = empty($queryVector)
                            ? $textVector
                            : collect($queryVector)->zip($textVector)->map(fn($p) => ($p[0] + $p[1]) / 2)->toArray();

                        Log::debug('[ProcessSearchJob] Vector partner ajustado con texto', [
                            'had_profile_vector' => !empty($archetypeProfile?->player_style_vector),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('[ProcessSearchJob] Falló optimización/embedding de texto para partner', ['error' => $e->getMessage()]);
                }
            }

            if (empty($queryVector)) {
                $this->sendFollowUp($this->token, '🔍 Incluye `texto:` con lo que buscas, o completa tu `/ficha` para buscar por perfil.');
                return;
            }

            // guild_ids en Qdrant son UUIDs internos de la tabla guilds, no Discord guild IDs
            $dbGuild = $this->guildId
                ? \App\Domains\Community\Models\Guild::where('discord_guild_id', $this->guildId)->first()
                : null;

            $must = [
                ['key' => 'entity_type',  'match' => ['value' => 'player_profile']],
                ['key' => 'archetype_id', 'match' => ['value' => $archetype->id]],
                ['key' => 'is_available', 'match' => ['value' => true]],
            ];
            if ($dbGuild) {
                $must[] = ['key' => 'guild_ids', 'match' => ['value' => $dbGuild->id]];
            }

            $mustNot = $archetypeProfile
                ? [['key' => 'player_profile_id', 'match' => ['value' => $archetypeProfile->id]]]
                : [];

            Log::debug('[ProcessSearchJob] Búsqueda partner en matchmaking_hub', [
                'archetype'    => $archetype->qdrant_vector_name,
                'db_guild_id'  => $dbGuild?->id,
                'has_profile'  => (bool) $archetypeProfile,
                'has_texto'    => !empty($this->texto),
            ]);

            $rawResults = $qdrant->searchHub('player_style', $queryVector, $must, $mustNot, 10);

            if (empty($rawResults)) {
                $this->sendFollowUp($this->token, 'No se encontraron partners compatibles en este servidor todavía.');
                return;
            }

            $profileIds = array_values(array_filter(
                array_map(fn($r) => $r['payload']['player_profile_id'] ?? null, $rawResults)
            ));
            $profiles = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::with('player')
                ->whereIn('id', $profileIds)
                ->get()
                ->keyBy('id');

            $embed = [
                'title'       => '🎯 Matchmaker: Partners Compatibles',
                'color'       => hexdec('5865F2'),
                'description' => 'Perfiles que encajan con tu búsqueda'
                    . (!empty($this->texto) ? " (texto: \"{$this->texto}\")" : '') . ':',
                'fields'      => [],
                'footer'      => ['text' => 'MUDRAIS Matchmaking System'],
            ];

            $components = [];
            $rank = 0;
            foreach ($rawResults as $result) {
                $pid     = $result['payload']['player_profile_id'] ?? null;
                $profile = $pid ? $profiles->get($pid) : null;
                if (! $profile || ! $profile->player) continue;
                if ($profile->player->discord_id === $this->discordId) continue;

                $rank++;
                $score   = round((float) $result['score'] * 100, 1);
                $flag    = !empty($profile->player->country_code) ? " :flag_{$profile->player->country_code}:" : '';
                $mention = "<@{$profile->player->discord_id}>";
                $sched   = !empty($profile->schedule_raw)         ? "\n🕐 {$profile->schedule_raw}" : '';
                $about   = !empty($profile->player->about_me)
                    ? "\n💬 " . mb_strimwidth($profile->player->about_me, 0, 100, '…')
                    : (!empty($profile->metadata['about_me'])
                        ? "\n💬 " . mb_strimwidth($profile->metadata['about_me'], 0, 100, '…')
                        : '');

                $embed['fields'][] = [
                    'name'   => "#{$rank} — {$mention}{$flag} (Afinidad: {$score}%)",
                    'value'  => trim("Jugador compatible{$sched}{$about}") ?: 'Sin descripción adicional.',
                    'inline' => false,
                ];

                $components[] = [
                    'type'       => 1,
                    'components' => [[
                        'type'      => 2,
                        'style'     => 2,
                        'label'     => "#{$rank} Ver Perfil",
                        'custom_id' => "player_profile_view:{$pid}",
                    ]],
                ];

                if ($rank >= 5) break;
            }

            if (empty($embed['fields'])) {
                $this->sendFollowUp($this->token, 'No se encontraron partners compatibles en este servidor todavía.');
                return;
            }

            Log::info('[ProcessSearchJob] Resultados partner enviados desde matchmaking_hub', [
                'discord_id' => $this->discordId,
                'count'      => $rank,
            ]);
            $messagePayload = ['embeds' => [$embed]];
            if (! empty($components)) {
                $messagePayload['components'] = $components;
            }
            $this->sendFollowUp($this->token, '', $messagePayload);
            return;
        }

        // Búsqueda de Entidades en matchmaking_hub (Avatar, Activity, etc)
        $player = Player::where('discord_id', $this->discordId)->first();
        if (! $player) {
            $this->sendFollowUp($this->token, 'No se pudo identificar tu perfil. Usa `/registro` primero.');
            return;
        }

        $entityType = ArchetypeEntityType::with('archetype')->find($this->objetivo);
        if (! $entityType) {
            $this->sendFollowUp($this->token, 'El tipo de búsqueda no es válido o no existe.');
            return;
        }

        $vault = $this->channelId ? Vault::where('discord_channel_id', $this->channelId)->first() : null;
        if (! $vault) {
            $this->sendFollowUp($this->token, 'Debes ejecutar este comando desde el canal del Vault correspondiente.');
            return;
        }

        if ($vault->primaryArchetype()?->id !== $entityType->archetype_id) {
            $this->sendFollowUp($this->token, 'El tipo de contexto no pertenece al arquetipo de este Vault.');
            return;
        }

        // Obtener el vector base del jugador
        $queryVector = [];
        $archetypeProfile = \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::where('player_id', $player->id)
            ->where('archetype_id', $entityType->archetype_id)
            ->first();

        if ($archetypeProfile && !empty($archetypeProfile->player_style_vector)) {
            $queryVector = $archetypeProfile->player_style_vector;
        }

        if (empty($queryVector)) {
            $this->sendFollowUp($this->token, 'Tu perfil de jugador aún no está indexado para este Arquetipo.');
            return;
        }

        // Si el usuario incluyó texto, lo optimizamos usando la estructura del Entity Type y lo vectorizamos
        if (!empty($this->texto)) {
            try {
                // 1. Construir el prompt de optimización estructurando la búsqueda
                $builtPrompt = $promptBuilder->buildPrompt($entityType, [
                    'Búsqueda o Idea del Usuario' => $this->texto
                ], $vault);

                // 2. Optimizar el texto a través del LLM si el prompt no está vacío
                $textToEmbed = $this->texto;
                if (!empty($builtPrompt)) {
                    $optimized = $contextOptimizer->optimize($builtPrompt, $player->id);
                    $textToEmbed = $optimized['optimized_text_en'] ?? $this->texto;
                    Log::info('[ProcessSearchJob] Texto de búsqueda optimizado', ['original' => $this->texto, 'optimized_length' => strlen($textToEmbed)]);
                }

                // 3. Generar el Embedding
                $model = config('services.openrouter.embedding_model', 'nvidia/llama-nemotron-embed-vl-1b-v2:free');
                $textVector = $gateway->embed($model, $textToEmbed);

                if (!empty($textVector)) {
                    // Promediar ambos vectores (Perfil 50% + Búsqueda 50%)
                    $queryVector = collect($queryVector)
                        ->zip($textVector)
                        ->map(fn($pair) => ($pair[0] + $pair[1]) / 2)
                        ->toArray();
                    Log::debug('[ProcessSearchJob] Vector ajustado con texto adicional optimizado.');
                }
            } catch (\Exception $e) {
                Log::warning('[ProcessSearchJob] Falló la optimización de búsqueda, ignorando el texto', ['error' => $e->getMessage()]);
            }
        }
        // Realizar búsqueda en la colección Hub (vector_name = 'avatar_context' o el que corresponda)
        $vectorName = match ($entityType->entity) {
            'activity' => 'activity_vibe',
            'avatar', 'character' => 'avatar_context',
            default => 'archetype_style',
        };

        // match.value es la sintaxis correcta en Qdrant para arrays (verifica si el valor está en el array).
        $must = [
            ['key' => 'entity_type', 'match' => ['value' => $entityType->entity]],
            ['key' => 'guild_ids',   'match' => ['value' => (string) $vault->id]],
        ];

        if (!empty($this->periodo)) {
            $cutoff = match ($this->periodo) {
                '24h'   => now()->subDay()->timestamp,
                '7d'    => now()->subWeek()->timestamp,
                '30d'   => now()->subDays(30)->timestamp,
                default => null,
            };
            if ($cutoff !== null) {
                $must[] = ['key' => 'created_at', 'range' => ['gte' => $cutoff]];
                Log::debug('[ProcessSearchJob] Filtro de tiempo aplicado', [
                    'periodo' => $this->periodo,
                    'cutoff'  => $cutoff,
                ]);
            }
        }

        // Se ignora el filtro Qdrant "creator_id" porque los payloads no siempre lo contienen.
        // El filtrado de creaciones propias se hace post-búsqueda en MatchmakingService.

        Log::debug('[ProcessSearchJob] Ejecutando searchHub', [
            'vectorName'  => $vectorName,
            'vault_id'    => $vault->id,
            'channel_id'  => $this->channelId,
            'mustFilters' => $must,
            'mustNot'     => [],
        ]);

        $rawResults = $qdrant->searchHub($vectorName, $queryVector, $must, [], 10);

        Log::debug('[ProcessSearchJob] Resultados crudos de Qdrant', [
            'raw_count'   => count($rawResults),
            'vault_id'    => $vault->id,
            'vectorName'  => $vectorName,
        ]);

        if (empty($rawResults)) {
            $this->sendFollowUp($this->token, "No se encontraron **{$entityType->type_label}** compatibles en este Vault.");
            return;
        }

        // Formatear resultados usando el MatchmakingService
        $results = $matchmaking->formatHubResults($player, $rawResults, $entityType);

        Log::debug('[ProcessSearchJob] Resultados tras filtro de creador', [
            'raw_count'      => count($rawResults),
            'filtered_count' => count($results),
            'player_id'      => $player->id,
        ]);

        if (empty($results)) {
            $this->sendFollowUp($this->token, "No se encontraron **{$entityType->type_label}** con suficiente compatibilidad.");
            return;
        }

        $top5 = array_slice($results, 0, 5);
        $embedFields = [];
        $components  = [];

        foreach ($top5 as $index => $match) {
            $posicion = $index + 1;
            $score    = round((float) $match['score'] * 100, 1);

            // --- Embed field ---
            $creatorLine = $match['creator_discord_id']
                ? "<@{$match['creator_discord_id']}>"
                : $match['creator_name'];

            $ctxParts = array_filter([$match['ctx1_name'] ?? null, $match['ctx2_name'] ?? null]);
            $ctxLine  = !empty($ctxParts) ? "\n🎭 Contexto: " . implode(' & ', $ctxParts) : '';
            $tagLine  = !empty($match['tags']) ? "\n🏷️ " . implode(', ', array_slice($match['tags'], 0, 5)) : '';
            $timeLine = $match['created_at'] ? "\n🕐 " . $match['created_at']->diffForHumans() : '';

            $embedFields[] = [
                'name'   => "#{$posicion} — 📜 {$match['name']} (Afinidad: {$score}%)",
                'value'  => "👤 {$creatorLine}\n💬 " . mb_strimwidth($match['description'], 0, 120, '…')
                            . $ctxLine . $tagLine . $timeLine,
                'inline' => false,
            ];

            // --- Action row: 1 row con 1-3 botones agrupados ---
            $rowButtons = [];

            if ($match['entity_id'] && $entityType->entity === 'activity') {
                $rowButtons[] = [
                    'type'      => 2,          // BUTTON
                    'style'     => 1,          // PRIMARY
                    'label'     => "#{$posicion} Ver actividad",
                    'custom_id' => "activity_view:{$match['entity_id']}",
                ];
            } elseif ($match['entity_id'] && ($entityType->entity === 'avatar' || $entityType->entity === 'character')) {
                $rowButtons[] = [
                    'type'      => 2,
                    'style'     => 1,
                    'label'     => "#{$posicion} Ver " . $entityType->entity,
                    'custom_id' => "avatar_view:{$match['entity_id']}",
                ];
            }

            if ($match['ctx1_id'] && $match['ctx1_name']) {
                $rowButtons[] = [
                    'type'      => 2,
                    'style'     => 2,          // SECONDARY
                    'label'     => mb_strimwidth($match['ctx1_name'], 0, 80, '…'),
                    'custom_id' => "avatar_view:{$match['ctx1_id']}",
                ];
            }
            if ($match['ctx2_id'] && $match['ctx2_name']) {
                $rowButtons[] = [
                    'type'      => 2,
                    'style'     => 2,
                    'label'     => mb_strimwidth($match['ctx2_name'], 0, 80, '…'),
                    'custom_id' => "avatar_view:{$match['ctx2_id']}",
                ];
            }
            if (!empty($rowButtons)) {
                $components[] = ['type' => 1, 'components' => $rowButtons];
            }
        }

        $embed = [
            'title'       => "🎯 Matchmaker: Top " . count($top5) . " {$entityType->type_label}s",
            'color'       => hexdec('5865F2'),
            'description' => "Resultados más afines a tu vibra"
                . ($this->texto   ? " (texto personalizado)" : "")
                . ($this->periodo ? " · últimas {$this->periodo}" : "") . ".",
            'fields'      => $embedFields,
            'footer'      => ['text' => 'MUDRAIS Matchmaking System'],
        ];

        $messagePayload = ['embeds' => [$embed]];
        if (!empty($components)) {
            $messagePayload['components'] = $components;  // ya ≤ 5 rows
        }
        $this->sendFollowUp($this->token, '', $messagePayload);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[ProcessSearchJob] Job fallido — notificando al usuario', [
            'discord_id' => $this->discordId,
            'error'      => $e->getMessage(),
        ]);

        $this->sendFollowUp(
            $this->token,
            '⚠️ La búsqueda tardó demasiado o encontró un error. Inténtalo de nuevo en un momento.',
            [],
            true
        );
    }
}
