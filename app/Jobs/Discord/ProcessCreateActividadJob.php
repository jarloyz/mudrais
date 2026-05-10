<?php

namespace App\Jobs\Discord;

use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Enums\ActivityStatus;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use App\Services\Discord\DiscordApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCreateActividadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        private string  $token,
        private string  $discordUserId,
        private string  $vaultId,
        private string     $activityTypeId,
        private string  $titulo,
        private string  $extraContext,
        private ?string $ctx1Id,
        private ?string $ctx2Id,
    ) {}

    public function handle(DiscordApiService $discordApi): void
    {
        Log::info('[ProcessCreateActividadJob] Iniciando', [
            'discord_user_id'  => $this->discordUserId,
            'vault_id'         => $this->vaultId,
            'activity_type_id' => $this->activityTypeId,
            'ctx1_id'          => $this->ctx1Id,
            'ctx2_id'          => $this->ctx2Id,
        ]);

        $vault = Vault::with('guild')->find($this->vaultId);
        if (! $vault) {
            Log::error('[ProcessCreateActividadJob] Vault no encontrado', ['vault_id' => $this->vaultId]);
            $this->sendFollowUp($this->token, '❌ El Vault ya no existe o fue eliminado.', [], true);
            return;
        }

        $activityType = ArchetypeEntityType::find($this->activityTypeId);
        if (! $activityType) {
            Log::error('[ProcessCreateActividadJob] ArchetypeEntityType no encontrado', [
                'id' => $this->activityTypeId,
            ]);
            $this->sendFollowUp($this->token, '❌ Tipo de actividad inválido. Contacta a un administrador.', [], true);
            return;
        }

        $player = Player::where('discord_id', $this->discordUserId)->first();
        if (! $player) {
            Log::warning('[ProcessCreateActividadJob] Player no encontrado', [
                'discord_user_id' => $this->discordUserId,
            ]);
            $this->sendFollowUp($this->token, '⚠️ No se encontró tu perfil de jugador. Usa `/registro` primero.', [], true);
            return;
        }

        $profile = PlayerArchetypeProfile::where('player_id', $player->id)
            ->where('archetype_id', $activityType->archetype_id)
            ->first();

        if (! $profile) {
            Log::warning('[ProcessCreateActividadJob] Sin perfil de arquetipo', [
                'player_id'    => $player->id,
                'archetype_id' => $activityType->archetype_id,
            ]);
            $this->sendFollowUp(
                $this->token,
                '⚠️ No tienes perfil para este arquetipo. Completa `/registro` primero.',
                [],
                true
            );
            return;
        }

        // Resolver contextos — pueden ser personajes, locaciones, u otro tipo de entidad
        $ctx1 = $this->ctx1Id ? Avatar::find($this->ctx1Id) : null;
        $ctx2 = $this->ctx2Id ? Avatar::find($this->ctx2Id) : null;

        Log::debug('[ProcessCreateActividadJob] Contextos resueltos', [
            'ctx1_name'       => $ctx1?->name,
            'ctx1_qdrant_id'  => $ctx1?->avatar_hub_qdrant_id,
            'ctx2_name'       => $ctx2?->name,
            'ctx2_qdrant_id'  => $ctx2?->avatar_hub_qdrant_id,
        ]);

        // Construir activity_description enriquecida con todos los contextos.
        // Esta cadena es la que IndexActivityJob embebe como vector activity_vibe.
        // Los qdrant_ids de ctx1/ctx2 se guardan en content_raw para que
        // IndexActivityJob los copie como ctx1_context y ctx2_context en el hub.
        $descriptionParts = array_filter([
            $ctx1 ? "[{$ctx1->name}]" : null,
            $ctx2 ? "[{$ctx2->name}]" : null,
            $this->titulo,
            filled($this->extraContext) ? $this->extraContext : null,
        ]);
        $activityDescription = implode(' ', $descriptionParts);

        $activity = Activity::create([
            'id'                       => (string) Str::uuid(),
            'vault_id'                 => $vault->id,
            'creator_profile_id'       => $profile->id,
            'archetype_entity_type_id' => $this->activityTypeId,
            'title'                    => $this->titulo,
            'activity_description'     => $activityDescription,
            'semantic_tag_query'       => $activityDescription, // IndexActivityJob lo refinará vía ContextOptimizer
            'content_raw'              => [
                'ctx1_id'        => $this->ctx1Id,
                'ctx1_name'      => $ctx1?->name,
                'ctx1_qdrant_id' => $ctx1?->avatar_hub_qdrant_id,
                'ctx2_id'        => $this->ctx2Id,
                'ctx2_name'      => $ctx2?->name,
                'ctx2_qdrant_id' => $ctx2?->avatar_hub_qdrant_id,
                'extra_context'  => $this->extraContext,
            ],
            'is_hub_indexed'  => false,
            'requires_avatar' => false,
            'status'          => ActivityStatus::RECRUITING,
        ]);

        Log::info('[ProcessCreateActividadJob] Activity creada en BD', [
            'activity_id'          => $activity->id,
            'activity_description' => $activityDescription,
            'status'               => ActivityStatus::RECRUITING->value,
        ]);

        // Despachar indexación vectorial + auto-asignación de tags (queue 'heavy')
        \App\Jobs\IndexActivityJob::dispatch($activity->id);
        Log::info('[ProcessCreateActividadJob] IndexActivityJob despachado', ['activity_id' => $activity->id]);

        $fields = [
            ['name' => 'Vault',    'value' => $vault->name,  'inline' => true],
            ['name' => 'Búsqueda', 'value' => $this->titulo, 'inline' => false],
        ];

        if ($ctx1) {
            $fields[] = ['name' => 'Contexto 1', 'value' => $ctx1->name, 'inline' => true];
        }
        if ($ctx2) {
            $fields[] = ['name' => 'Contexto 2', 'value' => $ctx2->name, 'inline' => true];
        }

        $this->sendFollowUp($this->token, '', [
            'embeds' => [
                [
                    'title'       => '✅ Actividad publicada',
                    'description' => "**{$this->titulo}** está en el hub de matchmaking y siendo indexada.",
                    'color'       => 0x57F287,
                    'fields'      => $fields,
                    'footer'      => ['text' => 'Los tags y el vector semántico se asignan en segundo plano.'],
                ],
                $this->buildActivityEmbed($vault, $ctx1, $ctx2),
            ],
        ], true);
    }

    private function buildActivityEmbed(Vault $vault, ?Avatar $ctx1, ?Avatar $ctx2): array
    {
        $guildId = $vault->guild->discord_guild_id ?? null;

        $fields = [
            ['name' => 'Jugador', 'value' => "<@{$this->discordUserId}>", 'inline' => true],
            ['name' => 'Vault',   'value' => $vault->name,                'inline' => true],
            ['name' => 'Estado',  'value' => '🟢 Buscando compañero/a',   'inline' => true],
        ];

        if ($ctx1) {
            $ctx1Value = ($guildId && $ctx1->discord_thread_id)
                ? "[{$ctx1->name}](https://discord.com/channels/{$guildId}/{$ctx1->discord_thread_id})"
                : $ctx1->name;
            $fields[] = ['name' => 'Contexto 1', 'value' => $ctx1Value, 'inline' => true];
        }
        if ($ctx2) {
            $ctx2Value = ($guildId && $ctx2->discord_thread_id)
                ? "[{$ctx2->name}](https://discord.com/channels/{$guildId}/{$ctx2->discord_thread_id})"
                : $ctx2->name;
            $fields[] = ['name' => 'Contexto 2', 'value' => $ctx2Value, 'inline' => true];
        }
        if (filled($this->extraContext)) {
            $fields[] = ['name' => 'Detalles', 'value' => mb_substr($this->extraContext, 0, 1024), 'inline' => false];
        }

        return [
            'title'       => $this->titulo,
            'description' => "Publicado en **{$vault->name}**. Usa `/buscar-actividad` para encontrar actividades compatibles.",
            'color'       => 0x5865F2,
            'fields'      => $fields,
            'footer'      => ['text' => 'Los tags y el vector semántico se asignan en segundo plano · MUDRAIS Matchmaking'],
        ];
    }

}
