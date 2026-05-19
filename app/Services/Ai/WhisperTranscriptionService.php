<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transcribe archivos de audio a texto usando la API de OpenAI Whisper.
 * Usado por ProcessGatewayMessageJob para mensajes de voz enviados en hilos de Bot Beta.
 */
class WhisperTranscriptionService
{
    private const WHISPER_ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';
    private const WHISPER_MODEL    = 'whisper-1';

    public function transcribe(string $audioUrl): string
    {
        Log::debug('[WhisperTranscriptionService] Iniciando transcripción.', ['url' => $audioUrl]);

        $audioContent = Http::timeout(30)->get($audioUrl)->body();

        if (empty($audioContent)) {
            Log::warning('[WhisperTranscriptionService] No se pudo descargar el audio.', ['url' => $audioUrl]);
            return '';
        }

        $response = Http::timeout(60)
            ->withToken((string) config('services.openai.key'))
            ->attach('file', $audioContent, 'audio.ogg')
            ->post(self::WHISPER_ENDPOINT, [
                'model' => self::WHISPER_MODEL,
            ]);

        if (! $response->successful()) {
            Log::error('[WhisperTranscriptionService] Error en la API de Whisper.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("Whisper API error: {$response->status()} — {$response->body()}");
        }

        $text = (string) ($response->json('text') ?? '');

        Log::info('[WhisperTranscriptionService] Transcripción completada.', [
            'chars' => strlen($text),
        ]);

        return $text;
    }
}
