<?php

namespace App\Services\Discord;

use App\Services\Discord\Contracts\DiscordWebhookClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductionWebhookClient implements DiscordWebhookClient
{
    private const BASE = 'https://discord.com/api/v10';

    public function sendFollowUp(string $token, string $content, array $extra = [], bool $ephemeral = false): void
    {
        if (str_starts_with($token, 'SCRIPT_')) {
            Log::debug('[ProductionWebhookClient@sendFollowUp] Token de script detectado, omitiendo llamada HTTP', [
                'token_prefix' => substr($token, 0, 8) . '...',
            ]);
            return;
        }

        // Application ID = Client ID in Discord. OAuth already works with client_id,
        // so we use it as the authoritative source and only fall back to app_id.
        $appId = config('services.discord.client_id') ?? config('services.discord.app_id');
        $url   = self::BASE . "/webhooks/{$appId}/{$token}/messages/@original";

        $body = array_filter(array_merge($extra, [
            'content' => $content ?: null,
            'flags'   => $ephemeral ? 64 : null,
        ]));

        Log::info('[ProductionWebhookClient@sendFollowUp] Enviando follow-up', [
            'token_prefix'  => substr($token, 0, 8) . '...',
            'app_id'        => $appId ? (substr($appId, 0, 4) . '…' . substr($appId, -4)) : 'NULL',
            'url'           => $url,
            'has_embeds'    => ! empty($extra['embeds']),
            'embed_count'   => count($extra['embeds'] ?? []),
            'ephemeral'     => $ephemeral,
            'body'          => $body,
        ]);

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->patch($url, $body);

            if (! $response->successful()) {
                Log::warning('[ProductionWebhookClient@sendFollowUp] Follow-up falló', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            } else {
                Log::info('[ProductionWebhookClient@sendFollowUp] Follow-up enviado correctamente', [
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[ProductionWebhookClient@sendFollowUp] Excepción', ['message' => $e->getMessage()]);
        }
    }
}
