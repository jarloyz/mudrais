<?php

namespace App\Services\Discord;

use App\Services\Discord\Contracts\DiscordSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductionSignatureValidator implements DiscordSignatureValidator
{
    public function __construct(private readonly string $publicKey) {}

    public function isValid(Request $request): bool
    {
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp  = $request->header('X-Signature-Timestamp');
        $body       = $request->getContent();

        if (!$signature || !$timestamp) {
            Log::warning('Discord: request rechazada — faltan cabeceras de firma.');
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                hex2bin($signature),
                $timestamp . $body,
                hex2bin($this->publicKey)
            );
        } catch (\Throwable $e) {
            Log::error('Discord: error al verificar firma — ' . $e->getMessage());
            return false;
        }
    }
}
