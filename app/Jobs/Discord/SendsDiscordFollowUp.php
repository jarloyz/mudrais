<?php

namespace App\Jobs\Discord;

use App\Services\Discord\Contracts\DiscordWebhookClient;

/**
 * Trait compartido por todos los jobs de Discord.
 *
 * Delega el envío del follow-up al DiscordWebhookClient enlazado en el container.
 * En producción y desarrollo se usa ProductionWebhookClient.
 */
trait SendsDiscordFollowUp
{
    private function sendFollowUp(string $token, string $content, array $extra = [], bool $ephemeral = false): void
    {
        app(DiscordWebhookClient::class)->sendFollowUp($token, $content, $extra, $ephemeral);
    }
}
