<?php

namespace App\Jobs\Discord;

use App\Domains\Narrative\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessViewActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string $token,
        public readonly string $activityId,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        Log::debug('[ProcessViewActivityJob] Iniciando', ['activity_id' => $this->activityId]);

        $activity = Activity::with(['vault.guild', 'creatorProfile.player', 'canonicalTags'])->find($this->activityId);

        if (!$activity) {
            $this->sendFollowUp($this->token, 'La actividad ya no existe o fue eliminada.', [], true);
            return;
        }

        $vault = $activity->vault;
        $creatorDiscordId = $activity->creatorProfile->player->discord_id ?? null;

        $statusLabel = $activity->status instanceof \App\Domains\Matchmaking\Enums\ActivityStatus
            ? $activity->status->label()
            : 'Activa';

        $fields = [
            ['name' => 'Jugador', 'value' => $creatorDiscordId ? "<@{$creatorDiscordId}>" : 'Desconocido', 'inline' => true],
            ['name' => 'Vault',   'value' => $vault->name,                'inline' => true],
            ['name' => 'Estado',  'value' => $statusLabel,   'inline' => true],
        ];

        $ctx1Name = $activity->content_raw['ctx1_name'] ?? null;
        $ctx2Name = $activity->content_raw['ctx2_name'] ?? null;

        if ($ctx1Name) {
            $fields[] = ['name' => 'Contexto 1', 'value' => $ctx1Name, 'inline' => true];
        }
        if ($ctx2Name) {
            $fields[] = ['name' => 'Contexto 2', 'value' => $ctx2Name, 'inline' => true];
        }

        $extraContext = $activity->content_raw['extra_context'] ?? $activity->content_raw['description'] ?? null;
        if (filled($extraContext)) {
            $fields[] = ['name' => 'Detalles', 'value' => mb_substr($extraContext, 0, 1024), 'inline' => false];
        }

        $tags = $activity->canonicalTags->pluck('name')->all();
        if (!empty($tags)) {
            $fields[] = ['name' => 'Tags', 'value' => implode(', ', $tags), 'inline' => false];
        }

        $embed = [
            'title'       => $activity->title ?? $activity->name ?? 'Actividad',
            'description' => "Publicado en **{$vault->name}**.",
            'color'       => 0x5865F2,
            'fields'      => $fields,
            'footer'      => ['text' => 'MUDRAIS Matchmaking'],
        ];

        $this->sendFollowUp($this->token, '', [
            'embeds' => [$embed],
        ], true);
    }
}
