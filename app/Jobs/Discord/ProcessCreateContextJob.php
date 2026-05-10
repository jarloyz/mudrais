<?php

namespace App\Jobs\Discord;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCreateContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        private string $token,
        private string $discordUserId,
        private string $vaultId,
        private string    $archetypeEntityTypeId,
        private string $contextName,
        private array  $contentRaw = []
    ) {}

    public function handle(\App\Services\Discord\DiscordApiService $discordApi): void
    {
        Log::info('[ProcessCreateContextJob] Iniciando creación de contexto', [
            'discord_user_id'          => $this->discordUserId,
            'vault_id'                 => $this->vaultId,
            'archetype_entity_type_id' => $this->archetypeEntityTypeId,
            'context_name'             => $this->contextName,
        ]);

        $vault = Vault::with('guild')->find($this->vaultId);

        if (! $vault) {
            Log::error('[ProcessCreateContextJob] Vault no encontrado', ['vault_id' => $this->vaultId]);
            $this->sendFollowUp($this->token, '❌ El Vault ya no existe o fue eliminado.', [], true);
            return;
        }

        $entityType = ArchetypeEntityType::find($this->archetypeEntityTypeId);

        if (! $entityType) {
            Log::error('[ProcessCreateContextJob] ArchetypeEntityType no encontrado', [
                'id' => $this->archetypeEntityTypeId,
            ]);
            $this->sendFollowUp($this->token, '❌ Tipo de contexto inválido. Contacta a un administrador.', [], true);
            return;
        }

        $profile = PlayerArchetypeProfile::where('discord_user_id', $this->discordUserId)
            ->where('archetype_id', $entityType->archetype_id)
            ->first();

        if (! $profile) {
            Log::warning('[ProcessCreateContextJob] Sin perfil de arquetipo para el jugador', [
                'discord_user_id' => $this->discordUserId,
                'archetype_id'    => $entityType->archetype_id,
            ]);
            $this->sendFollowUp(
                $this->token,
                '⚠️ No tienes un perfil registrado para este arquetipo. Completa `/registro` primero.',
                [],
                true
            );
            return;
        }

        $avatar = Avatar::create([
            'id'                       => (string) Str::uuid(),
            'name'                     => $this->contextName,
            'vault_id'                 => $vault->id,
            'owner_profile_id'         => $profile->id,
            'archetype_entity_type_id' => $this->archetypeEntityTypeId,
            'content_raw'              => $this->contentRaw,
            'is_lfg'                   => false,
        ]);

        Log::info('[ProcessCreateContextJob] Contexto creado exitosamente en BD', [
            'avatar_id'  => $avatar->id,
            'name'       => $avatar->name,
        ]);

        // Auto-vinculación: el creador puede usar su propio avatar como contexto en /actividad
        $profile->avatars()->syncWithoutDetaching([$avatar->id]);
        Log::info('[ProcessCreateContextJob] Avatar auto-vinculado al perfil del creador', [
            'avatar_id'  => $avatar->id,
            'profile_id' => $profile->id,
        ]);

        // Construir ficha para el follow-up efímero
        $mutators = \App\Domains\Matchmaking\Models\ArchetypeMutator::where('archetype_entity_type_id', $this->archetypeEntityTypeId)
            ->get()
            ->keyBy('field_key');

        $fichaFields = [
            ['name' => 'Propietario', 'value' => "<@{$this->discordUserId}>", 'inline' => true],
            ['name' => 'Vault',       'value' => $vault->name,               'inline' => true],
        ];

        foreach ($this->contentRaw as $key => $value) {
            if (blank($value)) continue;
            $label = $mutators->get($key)?->field_label ?? \Illuminate\Support\Str::headline($key);
            $fichaFields[] = [
                'name'   => $label,
                'value'  => mb_substr((string) $value, 0, 1024),
                'inline' => false,
            ];
        }

        // Encolar indexación vectorial del avatar
        \App\Jobs\IndexAvatarJob::dispatch($avatar->id);
        Log::info('[ProcessCreateContextJob] IndexAvatarJob despachado', ['avatar_id' => $avatar->id]);

        $this->sendFollowUp(
            $this->token,
            '',
            [
                'embeds' => [
                    [
                        'title'       => "✅ {$entityType->type_label} creado: {$avatar->name}",
                        'description' => "**{$avatar->name}** ha sido registrado en el Vault **{$vault->name}**.",
                        'color'       => 0x57F287,
                        'fields'      => [
                            ['name' => 'Tipo',  'value' => $entityType->type_label, 'inline' => true],
                            ['name' => 'Vault', 'value' => $vault->name,            'inline' => true],
                        ],
                    ],
                    [
                        'title'       => "Ficha de {$entityType->type_label}: {$avatar->name}",
                        'description' => "Ficha oficial de **{$avatar->name}**.",
                        'color'       => 0x7289DA,
                        'fields'      => $fichaFields,
                    ],
                ],
            ],
            true
        );
    }

}
