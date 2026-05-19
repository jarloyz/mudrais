<?php

namespace App\Services\Discord;

use App\Services\Discord\Contracts\DiscordSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestingSignatureValidator implements DiscordSignatureValidator
{
    public function __construct(
        private readonly string $fallbackPublicKey,
        private readonly array  $botsMap = [],
    ) {}

    public function isValid(Request $request): bool
    {
        $signature = $request->header('X-Signature-Ed25519');

        // Bypass para el comando de simulación CLI
        if ($signature === 'dummy_signature') {
            return true;
        }

        $timestamp  = $request->header('X-Signature-Timestamp');
        $body       = $request->getContent();

        if (!$signature || !$timestamp) {
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
            Log::error('[TestingSignatureValidator] Error al verificar firma real — ' . $e->getMessage());
            return false;
        }
    }

    private function resolvePublicKey(string $body): string
    {
        if (empty($this->botsMap)) {
            return $this->fallbackPublicKey;
        }

        $decoded = json_decode($body, true);
        $appId   = isset($decoded['application_id']) ? (string) $decoded['application_id'] : null;

        if ($appId && isset($this->botsMap[$appId])) {
            return (string) $this->botsMap[$appId];
        }

        return $this->fallbackPublicKey;
    }
}
