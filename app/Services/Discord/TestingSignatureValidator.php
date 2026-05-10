<?php

namespace App\Services\Discord;

use App\Services\Discord\Contracts\DiscordSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestingSignatureValidator implements DiscordSignatureValidator
{
    public function __construct(private readonly string $publicKey) {}

    public function isValid(Request $request): bool
    {
        $signature = $request->header('X-Signature-Ed25519');

        // 1. Permitir bypass para el comando de simulación CLI
        if ($signature === 'dummy_signature') {
            return true;
        }

        // 2. Si no es bypass, validar como una petición real de Discord
        $timestamp  = $request->header('X-Signature-Timestamp');
        $body       = $request->getContent();

        if (!$signature || !$timestamp) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                hex2bin($signature),
                $timestamp . $body,
                hex2bin($this->publicKey)
            );
        } catch (\Throwable $e) {
            Log::error('TestingSignatureValidator: error al verificar firma real — ' . $e->getMessage());
            return false;
        }
    }
}
