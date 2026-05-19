<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyVoiceBridgeSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        // No logear los polls frecuentes de pending-start para evitar ruido.
        if ($request->path() !== 'api/voice/pending-start') {
            Log::debug('[VerifyVoiceBridgeSecret] Verificando secreto del voice-bridge', [
                'ip'         => $request->ip(),
                'path'       => $request->path(),
                'has_header' => $request->hasHeader('X-Voice-Bridge-Secret'),
            ]);
        }

        $secret = $request->header('X-Voice-Bridge-Secret');

        if (!$secret) {
            Log::warning('[VerifyVoiceBridgeSecret] Header X-Voice-Bridge-Secret ausente', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $gammaToken = $this->resolveGammaBotToken();

        if ($gammaToken === null) {
            Log::error('[VerifyVoiceBridgeSecret] Bot Gamma no configurado en services.discord.bots');
            return response()->json(['error' => 'Voice bridge not configured'], 503);
        }

        if (!hash_equals($gammaToken, $secret)) {
            Log::warning('[VerifyVoiceBridgeSecret] Secreto inválido', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }

    private function resolveGammaBotToken(): ?string
    {
        foreach (config('services.discord.bots', []) as $bot) {
            if (($bot['slug'] ?? null) === 'gamma' && isset($bot['bot_token'])) {
                return (string) $bot['bot_token'];
            }
        }

        return null;
    }
}
