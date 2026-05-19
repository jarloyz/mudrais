<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transcribe archivos de audio a texto usando la API Batch de Speechmatics.
 * Reemplaza WhisperTranscriptionService. Misma interfaz: transcribe(url): string.
 *
 * Flujo:
 *   1. Descarga el audio desde la URL de Discord.
 *   2. POST /jobs/ → obtiene job_id.
 *   3. Polling GET /jobs/{id} hasta status=done.
 *   4. GET /jobs/{id}/transcript?format=txt → texto plano.
 */
class SpeechmaticsTranscriptionService
{
    private const BASE_URL      = 'https://asr.api.speechmatics.com/v2';
    private const POLL_INTERVAL = 3;  // segundos entre comprobaciones de estado
    private const MAX_POLLS     = 15; // máx 45 s de espera de procesamiento

    public function transcribe(string $audioUrl, ?string $language = null): string
    {
        $apiKey   = (string) config('services.speechmatics.key');
        $language = $language ?? (string) config('services.speechmatics.language', 'es');

        Log::debug('[SpeechmaticsTranscriptionService] Descargando audio.', ['url' => $audioUrl]);

        $audioContent = Http::timeout(30)->get($audioUrl)->body();

        if (empty($audioContent)) {
            Log::warning('[SpeechmaticsTranscriptionService] No se pudo descargar el audio.', ['url' => $audioUrl]);
            return '';
        }

        // ── 1. Enviar job ─────────────────────────────────────────────────────
        $config = json_encode([
            'type'                 => 'transcription',
            'transcription_config' => [
                'language'        => $language,
                'operating_point' => 'standard',
            ],
        ]);

        Log::debug('[SpeechmaticsTranscriptionService] Enviando job.', ['language' => $language]);

        $submitResponse = Http::timeout(30)
            ->withToken($apiKey)
            ->asMultipart()
            ->attach('data_file', $audioContent, 'audio.ogg')
            ->attach('config', $config, null, ['Content-Type' => 'application/json'])
            ->post(self::BASE_URL . '/jobs/');

        if (! $submitResponse->successful()) {
            throw new \RuntimeException(
                "Speechmatics submit error: {$submitResponse->status()} — {$submitResponse->body()}"
            );
        }

        $jobId = $submitResponse->json('id');

        Log::info('[SpeechmaticsTranscriptionService] Job enviado.', ['job_id' => $jobId]);

        // ── 2. Polling hasta done/rejected ───────────────────────────────────
        $status = 'running';

        for ($poll = 0; $poll < self::MAX_POLLS; $poll++) {
            sleep(self::POLL_INTERVAL);

            $statusResponse = Http::timeout(15)
                ->withToken($apiKey)
                ->get(self::BASE_URL . "/jobs/{$jobId}");

            $status = $statusResponse->json('job.status') ?? 'unknown';

            Log::debug('[SpeechmaticsTranscriptionService] Poll.', [
                'job_id' => $jobId,
                'poll'   => $poll + 1,
                'status' => $status,
            ]);

            if ($status === 'done' || $status === 'rejected') {
                break;
            }
        }

        if ($status !== 'done') {
            throw new \RuntimeException(
                "Speechmatics job no completado (status={$status}, job={$jobId})"
            );
        }

        // ── 3. Obtener transcripción en texto plano ───────────────────────────
        $transcriptResponse = Http::timeout(15)
            ->withToken($apiKey)
            ->get(self::BASE_URL . "/jobs/{$jobId}/transcript", ['format' => 'txt']);

        $text = trim($transcriptResponse->body());

        Log::info('[SpeechmaticsTranscriptionService] Transcripción completada.', [
            'job_id' => $jobId,
            'chars'  => strlen($text),
            'text'   => $text,
        ]);

        return $text;
    }
}
