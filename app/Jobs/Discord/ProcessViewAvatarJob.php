<?php

namespace App\Jobs\Discord;

use App\Domains\Narrative\Models\Avatar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessViewAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string $token,
        public readonly string $avatarId,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        Log::debug('[ProcessViewAvatarJob] Iniciando', ['avatar_id' => $this->avatarId]);

        $avatar = Avatar::with(['vault.guild', 'ownerProfile.player', 'tags'])->find($this->avatarId);

        if (!$avatar) {
            $this->sendFollowUp($this->token, 'El contexto ya no existe o fue eliminado.', [], true);
            return;
        }

        $vault = $avatar->vault;
        $guildId = $vault->guild->discord_guild_id ?? null;
        $ownerDiscordId = $avatar->ownerProfile->player->discord_id ?? null;

        $fields = [
            ['name' => 'Jugador', 'value' => $ownerDiscordId ? "<@{$ownerDiscordId}>" : 'Desconocido', 'inline' => true],
            ['name' => 'Vault',   'value' => $vault->name,                'inline' => true],
        ];

        $tags = $avatar->tags->pluck('name')->all();
        if (!empty($tags)) {
            $fields[] = ['name' => 'Tags', 'value' => implode(', ', $tags), 'inline' => false];
        }

        // Obtener mutadores para traducir los keys del content_raw
        $mutators = \App\Domains\Matchmaking\Models\ArchetypeMutator::where('archetype_entity_type_id', $avatar->archetype_entity_type_id)
            ->get()
            ->keyBy('field_key');

        if (!empty($avatar->content_raw)) {
            foreach ($avatar->content_raw as $key => $value) {
                if (blank($value)) continue;
                $label = $mutators->get($key)?->field_label ?? \Illuminate\Support\Str::headline($key);
                $fields[] = [
                    'name'   => $label,
                    'value'  => mb_substr((string) $value, 0, 1024),
                    'inline' => false,
                ];
            }
        }

        // Obtener la descripción optimizada si existe
        if (!empty($avatar->optimized_text)) {
            $description = mb_substr($avatar->optimized_text, 0, 1024);
            $fields[] = ['name' => 'Resumen Optimizado', 'value' => $description, 'inline' => false];
        }

        $threadLine = ($guildId && $avatar->discord_thread_id)
            ? "\n🔗 [Ver hilo de contexto](https://discord.com/channels/{$guildId}/{$avatar->discord_thread_id})"
            : '';

        $embed = [
            'title'       => $avatar->name ?? 'Contexto',
            'description' => "Contexto indexado en **{$vault->name}**." . $threadLine,
            'color'       => 0x5865F2,
            'fields'      => $fields,
            'footer'      => ['text' => 'MUDRAIS Matchmaking'],
        ];

        $this->sendFollowUp($this->token, '', [
            'embeds' => [$embed],
        ], true);
    }
}
