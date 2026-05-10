<?php

namespace App\Jobs\Discord;

use App\Application\Services\GatekeeperProfileService;
use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessFichaModalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string $discordId,
        public readonly string $profileText,
        public readonly string $token,
    ) {
        $this->onQueue('default');
    }

    public function handle(GatekeeperProfileService $service): void
    {
        Log::debug('[ProcessFichaModalJob] Buscando player', ['discord_id' => $this->discordId]);

        $player = Player::where('discord_id', $this->discordId)->first();

        if (! $player) {
            Log::warning('[ProcessFichaModalJob] Player no encontrado', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, 'No tienes perfil registrado. Usa `/registro` primero.', ephemeral: true);
            return;
        }

        Log::info('[ProcessFichaModalJob] Procesando ficha', [
            'player_id'   => $player->id,
            'text_length' => strlen($this->profileText),
        ]);

        $detail = $service->processPlayerProfile($player, $this->profileText);

        if ($detail === null) {
            Log::warning('[ProcessFichaModalJob] GatekeeperProfileService devolvió null', ['player_id' => $player->id]);
            $this->sendFollowUp(
                $this->token,
                "Hubo un problema al procesar tu ficha, <@{$player->discord_id}>. Por favor inténtalo de nuevo.",
                ephemeral: true
            );
            return;
        }

        Log::info('[ProcessFichaModalJob] Ficha procesada correctamente', ['player_id' => $player->id]);
        $this->sendFollowUp(
            $this->token,
            "¡Ficha procesada, <@{$player->discord_id}>! Tu perfil ha sido vectorizado y ya eres visible en las búsquedas de partida.",
            ephemeral: true
        );
    }
}
