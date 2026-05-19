<?php

namespace App\Jobs\Discord;

use App\Infrastructure\Discord\Embeds\RegistroEmbeds;
use App\Services\Discord\DiscordApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendRegistroSuccessMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string  $token,
        public readonly string  $discordId,
        public readonly bool    $isEdit,
        public readonly ?string $username = null,
        public readonly ?string $threadId = null,
        public readonly ?string $guildId  = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $displayName = $this->username ?? $this->discordId;

        Log::info('[SendRegistroSuccessMessageJob] Enviando mensaje final de éxito', [
            'discord_id' => $this->discordId,
            'is_edit'    => $this->isEdit,
            'username'   => $displayName,
        ]);

        $embedData = $this->isEdit
            ? RegistroEmbeds::exitoEdicion($displayName, 0)
            : RegistroEmbeds::exitoRegistro($displayName);

        if ($this->threadId && $this->guildId) {
            Log::debug('[SendRegistroSuccessMessageJob] Enviando al hilo privado', [
                'thread_id' => $this->threadId,
                'guild_id'  => $this->guildId,
            ]);
            DiscordApiService::forGuild($this->guildId)
                ->sendMessage($this->threadId, $embedData);
        } else {
            $this->sendFollowUp($this->token, '', $embedData);
        }
    }
}
