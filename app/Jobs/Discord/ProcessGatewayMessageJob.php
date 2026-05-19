<?php

namespace App\Jobs\Discord;

use App\Domains\Community\Models\Player;
use App\Models\InterviewMessage;
use App\Services\Ai\SpeechmaticsTranscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Procesador de mensajes recibidos por Bot Beta en hilos privados.
 *
 * Flujo (con debounce):
 *   1. Resolver texto (audio → Speechmatics STT | texto plano).
 *   2. Verificar sesión de entrevista activa en cache.
 *   3. Persistir mensaje en interview_messages (is_processed=false).
 *   4. Actualizar timestamp de debounce en Redis.
 *   5. Despachar ProcessInterviewDebounceJob con 5 s de delay.
 */
class ProcessGatewayMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // 30s descarga + hasta 45s polling Speechmatics + margen
    public int $tries   = 2;

    private const DEBOUNCE_SECONDS = 5;

    public function __construct(
        public readonly string  $guildId,
        public readonly string  $threadId,
        public readonly string  $discordId,
        public readonly ?string $username,
        public readonly ?string $textContent,
        public readonly ?string $audioUrl,
    ) {
        $this->onQueue('default');
    }

    public function handle(SpeechmaticsTranscriptionService $speechmatics): void
    {
        Log::info('[ProcessGatewayMessageJob] Inicio', [
            'guild_id'   => $this->guildId,
            'thread_id'  => $this->threadId,
            'discord_id' => $this->discordId,
            'has_text'   => $this->textContent !== null,
            'has_audio'  => $this->audioUrl !== null,
        ]);

        $player = Player::where('discord_id', $this->discordId)->first();

        if (! $player) {
            Log::warning('[ProcessGatewayMessageJob] Player no encontrado', ['discord_id' => $this->discordId]);
            return;
        }

        App::setLocale($player->preferred_locale ?? 'es');

        $locale = $player->preferred_locale ?? 'es';
        $text   = $this->resolveText($speechmatics, $locale);

        if ($text === null) {
            Log::debug('[ProcessGatewayMessageJob] Sin contenido de texto, ignorando mensaje.', [
                'discord_id' => $this->discordId,
            ]);
            return;
        }

        $state = Cache::get("interview_state_{$this->discordId}");

        if (! $state) {
            Log::debug('[ProcessGatewayMessageJob] Sin sesión de entrevista activa para el usuario.', [
                'discord_id' => $this->discordId,
            ]);
            return;
        }

        // ── 1. Persistir mensaje ──────────────────────────────────────────────
        $message = InterviewMessage::create([
            'thread_id'    => $this->threadId,
            'discord_id'   => $this->discordId,
            'guild_id'     => $this->guildId,
            'content'      => $text,
            'is_processed' => false,
        ]);

        Log::info('[ProcessGatewayMessageJob] Mensaje persistido en interview_messages', [
            'message_id' => $message->id,
            'thread_id'  => $this->threadId,
            'chars'      => strlen($text),
        ]);

        // ── 2. Actualizar timestamp de debounce en Redis ──────────────────────
        $debounceKey = "interview_debounce_{$this->threadId}";
        $dispatchedAt = now()->getTimestampMs();

        Redis::setex($debounceKey, 30, $dispatchedAt);

        Log::debug('[ProcessGatewayMessageJob] Debounce key actualizada', [
            'key'          => $debounceKey,
            'dispatched_at' => $dispatchedAt,
        ]);

        // ── 3. Despachar debounce con delay ───────────────────────────────────
        ProcessInterviewDebounceJob::dispatch(
            threadId:     $this->threadId,
            discordId:    $this->discordId,
            guildId:      $this->guildId,
            username:     $this->username ?? $player->nickname ?? $player->discord_id,
            dispatchedAt: $dispatchedAt,
        )->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));

        Log::info('[ProcessGatewayMessageJob] ProcessInterviewDebounceJob despachado con delay', [
            'thread_id'    => $this->threadId,
            'delay_seconds' => self::DEBOUNCE_SECONDS,
            'dispatched_at' => $dispatchedAt,
        ]);
    }

    private function resolveText(SpeechmaticsTranscriptionService $speechmatics, string $locale = 'es'): ?string
    {
        if ($this->audioUrl !== null) {
            try {
                $transcribed = $speechmatics->transcribe($this->audioUrl, $locale);
                Log::info('[ProcessGatewayMessageJob] Audio transcrito vía Speechmatics.', [
                    'discord_id' => $this->discordId,
                    'chars'      => strlen($transcribed),
                ]);
                return $transcribed ?: null;
            } catch (\Throwable $e) {
                Log::warning('[ProcessGatewayMessageJob] Error transcribiendo audio — descartando mensaje de voz.', [
                    'discord_id' => $this->discordId,
                    'error'      => $e->getMessage(),
                ]);
                return null; // no fallback a texto (el mensaje era solo audio)
            }
        }

        return $this->textContent;
    }
}
