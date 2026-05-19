<?php

namespace App\Jobs\Discord;

use App\Models\InterviewMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Ejecuta el turno de entrevista solo si es el despacho más reciente (debounce).
 *
 * Flujo:
 *   1. Comparar $dispatchedAt con el timestamp almacenado en Redis.
 *      Si no coincide, un mensaje posterior ya programó su propio debounce — salir.
 *   2. Adquirir lock atómico para evitar procesamiento concurrente del mismo hilo.
 *   3. Recolectar todos los InterviewMessage no procesados del hilo.
 *   4. Concatenarlos como bloque de texto y marcarlos como procesados.
 *   5. Leer el turno actual del cache de sesión y despachar ProcessInterviewTurnJob.
 */
class ProcessInterviewDebounceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 1; // Sin reintentos: si pierde la carrera, no debe re-ejecutarse

    private const LOCK_SECONDS = 20;

    public function __construct(
        public readonly string $threadId,
        public readonly string $discordId,
        public readonly string $guildId,
        public readonly string $username,
        public readonly int    $dispatchedAt,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::debug('[ProcessInterviewDebounceJob] Inicio', [
            'thread_id'    => $this->threadId,
            'discord_id'   => $this->discordId,
            'dispatched_at' => $this->dispatchedAt,
        ]);

        // ── 1. Verificar que seguimos siendo el despacho más reciente ─────────
        $debounceKey     = "interview_debounce_{$this->threadId}";
        $latestTimestamp = (int) Redis::get($debounceKey);

        if ($latestTimestamp !== $this->dispatchedAt) {
            Log::info('[ProcessInterviewDebounceJob] Despacho obsoleto — mensaje más reciente toma el control.', [
                'thread_id'    => $this->threadId,
                'dispatched_at' => $this->dispatchedAt,
                'latest'       => $latestTimestamp,
            ]);
            return;
        }

        // ── 2. Adquirir lock atómico ──────────────────────────────────────────
        $lockKey = "interview_processing_{$this->threadId}";
        $lock    = Cache::lock($lockKey, self::LOCK_SECONDS);

        if (! $lock->get()) {
            Log::warning('[ProcessInterviewDebounceJob] No se pudo adquirir el lock — hilo ya en procesamiento.', [
                'thread_id' => $this->threadId,
            ]);
            return;
        }

        try {
            // ── 3. Recolectar mensajes no procesados ──────────────────────────
            $messages = InterviewMessage::forThread($this->threadId)
                ->unprocessed()
                ->orderBy('created_at')
                ->get();

            if ($messages->isEmpty()) {
                Log::debug('[ProcessInterviewDebounceJob] Sin mensajes no procesados en el hilo.', [
                    'thread_id' => $this->threadId,
                ]);
                return;
            }

            Log::info('[ProcessInterviewDebounceJob] Mensajes a procesar', [
                'thread_id' => $this->threadId,
                'count'     => $messages->count(),
            ]);

            // ── 4. Concatenar y marcar como procesados ────────────────────────
            $batchedText = $messages->pluck('content')->implode("\n");

            InterviewMessage::whereIn('id', $messages->pluck('id'))->update(['is_processed' => true]);

            Log::debug('[ProcessInterviewDebounceJob] Mensajes marcados como procesados', [
                'thread_id' => $this->threadId,
                'ids'       => $messages->pluck('id')->toArray(),
                'chars'     => strlen($batchedText),
            ]);

            // ── 5. Leer turno de sesión y despachar turno de entrevista ───────
            $state = Cache::get("interview_state_{$this->discordId}");

            if (! $state) {
                Log::warning('[ProcessInterviewDebounceJob] Sesión de entrevista no encontrada en cache al procesar batch.', [
                    'discord_id' => $this->discordId,
                    'thread_id'  => $this->threadId,
                ]);
                return;
            }

            $turn = $state['turn'] ?? 1;

            Log::info('[ProcessInterviewDebounceJob] Despachando turno de entrevista con batch de mensajes.', [
                'discord_id' => $this->discordId,
                'thread_id'  => $this->threadId,
                'turn'       => $turn,
                'batch_chars' => strlen($batchedText),
            ]);

            ProcessInterviewTurnJob::dispatch(
                discordId:  $this->discordId,
                token:      null,
                userAnswer: $batchedText,
                turn:       $turn,
                username:   $this->username,
                guildId:    $this->guildId,
                threadId:   $this->threadId,
            );
        } finally {
            $lock->release();
        }
    }
}
