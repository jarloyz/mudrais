<?php

namespace App\Jobs\Discord;

use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessViewPlayerProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string $token,
        public readonly string $profileId,
    ) {
        $this->onQueue('high');
    }

    public function handle(ArchetypeMutatorService $mutatorService): void
    {
        Log::debug('[ProcessViewPlayerProfileJob] Iniciando', ['profile_id' => $this->profileId]);

        $profile = PlayerArchetypeProfile::with(['player'])->find($this->profileId);

        if (! $profile || ! $profile->player) {
            $this->sendFollowUp($this->token, '⚠️ Perfil no encontrado.', [], true);
            return;
        }

        $player = $profile->player;
        $flag   = !empty($player->country_code) ? " :flag_{$player->country_code}:" : '';

        $mutators = $mutatorService
            ->getFieldsForContext($profile->archetype_id, 'registration')
            ->keyBy('field_key');

        $fields = [
            ['name' => '👤 Jugador', 'value' => "<@{$player->discord_id}>", 'inline' => true],
        ];

        if (filled($profile->schedule_raw)) {
            $fields[] = ['name' => '🗓️ Disponibilidad', 'value' => $profile->schedule_raw, 'inline' => true];
        }

        if (!empty($profile->content_raw)) {
            foreach ($profile->content_raw as $key => $value) {
                if (blank($value)) continue;
                $label        = $mutators->get($key)?->field_label ?? Str::headline($key);
                $displayValue = is_array($value)
                    ? implode(', ', array_filter(array_map('strval', $value)))
                    : (string) $value;
                $fields[] = [
                    'name'   => $label,
                    'value'  => mb_substr($displayValue, 0, 1024) ?: '—',
                    'inline' => false,
                ];
            }
        }

        $embed = [
            'title'  => "{$player->username}{$flag}",
            'color'  => 0x5865F2,
            'fields' => $fields,
            'footer' => ['text' => 'MUDRAIS Matchmaking'],
        ];

        Log::info('[ProcessViewPlayerProfileJob] Perfil enviado', ['profile_id' => $this->profileId]);

        $this->sendFollowUp($this->token, '', ['embeds' => [$embed]], true);
    }
}
