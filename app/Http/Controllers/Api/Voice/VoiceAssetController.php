<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Voice\VoiceAssetService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VoiceAssetController extends Controller
{
    public function __construct(
        private readonly VoiceAssetService $assets,
    ) {}

    /**
     * GET /api/voice/assets/{archetypeId}/{filename}
     *
     * Sirve el archivo de audio WAV pre-generado para un archetype al voice-bridge.
     * El middleware voice.bridge ya valida la autenticación.
     * Devuelve 404 si el archivo no existe (el cliente debe caer back a TTS en tiempo real).
     */
    public function serve(string $archetypeId, string $filename): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        // Prevenir path traversal — solo nombres de archivo planos.
        $filename = basename($filename);

        // Permite archetype assets (opening, filler_N) y estáticos (greeting, still_there, goodbye, generic_filler_N).
        if (! preg_match('/^[a-z][a-z0-9_]{0,40}\.wav$/', $filename)) {
            Log::warning('[VoiceAssetController@serve] Filename inválido', [
                'archetype_id' => $archetypeId,
                'filename'     => $filename,
            ]);
            return response()->json(['error' => 'Invalid filename'], 400);
        }

        Log::debug('[VoiceAssetController@serve] Solicitud', [
            'archetype_id' => $archetypeId,
            'filename'     => $filename,
        ]);

        $path = $this->assets->assetPath($archetypeId, $filename);

        if ($path === null) {
            Log::debug('[VoiceAssetController@serve] Asset no encontrado', [
                'archetype_id' => $archetypeId,
                'filename'     => $filename,
            ]);
            return response()->json(['error' => 'Asset not found'], 404);
        }

        Log::debug('[VoiceAssetController@serve] Sirviendo', [
            'archetype_id' => $archetypeId,
            'filename'     => $filename,
            'size'         => filesize($path),
        ]);

        return response()->file($path, [
            'Content-Type'  => 'audio/wav',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
