<?php

namespace App\Jobs\Discord;

use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string $discordId,
        public readonly string $token,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        Log::debug('[ProcessStatusJob] Buscando player', ['discord_id' => $this->discordId]);

        $player = Player::where('discord_id', $this->discordId)->first();

        if (! $player) {
            Log::warning('[ProcessStatusJob] Player no encontrado', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, 'No tienes un perfil registrado. Usa `/registro` primero.', ephemeral: true);
            return;
        }

        $content = "**Estado de {$player->username}**\n"
            . "⚡ Energía: **{$player->energy}**\n"
            . "🪙 Monedas: **{$player->coin}**\n"
            . "📊 ELO: **{$player->elo}**";

        Log::info('[ProcessStatusJob] Follow-up enviado', ['player_id' => $player->id]);
        $this->sendFollowUp($this->token, $content, ephemeral: true);
    }
}
