<?php

namespace App\Services\Discord;

use App\Services\Discord\Contracts\DiscordSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductionSignatureValidator implements DiscordSignatureValidator
{
    /**
     * @param string $fallbackPublicKey  Legacy key (DISCORD_PUBLIC_KEY) — usado cuando
     *                                   application_id no se encuentra en el mapa de bots.
     * @param array  $botsMap            Mapa app_id → public_key para multi-bot.
     */
    public function __construct(
        private readonly string $fallbackPublicKey,
        private readonly array  $botsMap = [],
    ) {}

    public function isValid(Request $request): bool
    {
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp  = $request->header('X-Signature-Timestamp');
        $body       = $request->getContent();

        if (!$signature || !$timestamp) {
            Log::warning('[ProductionSignatureValidator] Request rechazada — faltan cabeceras de firma.');
            return false;
        }

        $publicKey = $this->resolvePublicKey($body);

        try {
            return sodium_crypto_sign_verify_detached(
                hex2bin($signature),
                $timestamp . $body,
                hex2bin($publicKey)
            );
        } catch (\Throwable $e) {
            Log::error('[ProductionSignatureValidator] Error al verificar firma — ' . $e->getMessage());
            return false;
        }
    }

    private function resolvePublicKey(string $body): string
    {
        if (empty($this->botsMap)) {
            return $this->fallbackPublicKey;
        }

        $appId = $this->extractAppId($body);

        if ($appId && isset($this->botsMap[$appId])) {
            return (string) $this->botsMap[$appId];
        }

        Log::debug('[ProductionSignatureValidator] application_id no encontrado en bots map, usando fallback.', [
            'app_id' => $appId,
        ]);

        return $this->fallbackPublicKey;
    }

    private function extractAppId(string $body): ?string
    {
        $decoded = json_decode($body, true);

        return isset($decoded['application_id']) ? (string) $decoded['application_id'] : null;
    }
}
