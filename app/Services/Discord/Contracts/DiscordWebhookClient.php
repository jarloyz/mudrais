<?php

namespace App\Services\Discord\Contracts;

interface DiscordWebhookClient
{
    /**
     * Envía (o simula enviar) el follow-up de una interacción de Discord.
     *
     * @param string               $token     Interaction token de Discord
     * @param string               $content   Texto del mensaje (puede ser vacío si se usan embeds)
     * @param array<string, mixed> $extra     Campos adicionales del payload (ej: ['embeds' => [...]])
     * @param bool                 $ephemeral Si el mensaje es solo visible para el invocador
     */
    public function sendFollowUp(string $token, string $content, array $extra = [], bool $ephemeral = false): void;
}
