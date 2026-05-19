<?php

namespace App\Services\Discord;

use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Narrative\Models\Vault;
use App\Domains\Community\Models\Guild;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DiscordApiService
{
    private string $baseUrl = 'https://discord.com/api/v10';
    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? config('services.discord.bot_token');
    }

    /**
     * Instancia el servicio con el token del bot de mayor tier instalado en el guild.
     * Fallback al token legacy si el guild no tiene bots registrados.
     */
    public static function forGuild(string $guildId): self
    {
        $appId = \App\Domains\Community\Models\Guild::where('discord_guild_id', $guildId)
            ->first()
            ?->bots()
            ->orderByDesc('tier')
            ->value('app_id');

        $token = $appId
            ? (config("services.discord.bots.{$appId}.bot_token") ?? config('services.discord.bot_token'))
            : config('services.discord.bot_token');

        return new self((string) $token);
    }

    public function getGuildChannels(string $guildId): ?array
    {
        $response = Http::withHeader('Authorization', 'Bot ' . $this->token)
            ->get("{$this->baseUrl}/guilds/{$guildId}/channels");

        if (!$response->successful()) {
            Log::error('[DiscordApiService@getGuildChannels] Falló la obtención de canales', [
                'guild_id' => $guildId,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }

    public function createChannel(string $guildId, array $data): ?array
    {
        $response = Http::withHeader('Authorization', 'Bot ' . $this->token)
            ->post("{$this->baseUrl}/guilds/{$guildId}/channels", $data);

        if (!$response->successful()) {
            Log::error('[DiscordApiService@createChannel] Falló la creación de canal', [
                'guild_id'   => $guildId,
                'data_keys'  => array_keys($data),
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }

    public function createThread(string $channelId, string $name, int $type = 11, array $message = []): ?array
    {
        $payload = [
            'name' => $name,
            'type' => $type,
        ];

        if (!empty($message)) {
            $payload['message'] = $message;
        }

        $response = Http::withHeader('Authorization', 'Bot ' . $this->token)
            ->post("{$this->baseUrl}/channels/{$channelId}/threads", $payload);

        if (!$response->successful()) {
            Log::error('[DiscordApiService@createThread] Falló la creación de hilo', [
                'channel_id' => $channelId,
                'name'       => $name,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }

    public function deleteChannel(string $channelId): bool
    {
        $response = Http::withHeader('Authorization', 'Bot ' . $this->token)
            ->delete("{$this->baseUrl}/channels/{$channelId}");

        if (!$response->successful() && $response->status() !== 404) {
            Log::error('[DiscordApiService@deleteChannel] Falló la eliminación de canal', [
                'channel_id' => $channelId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    public function sendMessage(string $channelId, array $data): ?array
    {
        $response = Http::withHeader('Authorization', 'Bot ' . $this->token)
            ->post("{$this->baseUrl}/channels/{$channelId}/messages", $data);

        if (!$response->successful()) {
            Log::error('[DiscordApiService@sendMessage] Falló el envío de mensaje', [
                'channel_id' => $channelId,
                'data_keys'  => array_keys($data),
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }

    public function editMessage(string $channelId, string $messageId, array $data): ?array
    {
        $response = Http::withHeader('Authorization', 'Bot ' . $this->token)
            ->patch("{$this->baseUrl}/channels/{$channelId}/messages/{$messageId}", $data);

        if (!$response->successful()) {
            Log::error('[DiscordApiService@editMessage] Falló la edición de mensaje', [
                'channel_id' => $channelId,
                'message_id' => $messageId,
                'data_keys'  => array_keys($data),
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }
}
