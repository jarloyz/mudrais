<?php

namespace App\Jobs\Discord;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\Player;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreateOnboardingThreadJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly string  $discordGuildId,
        public readonly string  $discordUserId,
        public readonly string  $username,
        public readonly string  $vaultName,
        public readonly string  $interactionToken,
        public readonly ?string $archetypeId = null,
    ) {}

    public function handle(InterviewerAgent $agent): void
    {
        App::setLocale($this->resolveLocale());

        Log::info('[CreateOnboardingThreadJob] Inicio', [
            'guild_id'  => $this->discordGuildId,
            'user_id'   => $this->discordUserId,
            'vault'     => $this->vaultName,
        ]);

        $guild = Guild::where('discord_guild_id', $this->discordGuildId)->first();

        if (! $guild) {
            Log::error('[CreateOnboardingThreadJob] Guild no encontrada', [
                'discord_guild_id' => $this->discordGuildId,
            ]);
            return;
        }

        if (! $guild->onboarding_channel_id) {
            Log::warning('[CreateOnboardingThreadJob] Guild sin canal de onboarding configurado — omitiendo hilo beta', [
                'guild_id' => $guild->id,
            ]);
            return;
        }

        $betaToken = env('DISCORD_BOT_TOKEN_BETA');
        if (! $betaToken) {
            Log::error('[CreateOnboardingThreadJob] DISCORD_BOT_TOKEN_BETA no configurado');
            return;
        }

        $channelId  = $guild->onboarding_channel_id;
        $threadName = "Entrevista · {$this->username} · {$this->vaultName}";

        // ── 1. Crear hilo privado en el canal de onboarding ───────────────────
        Log::debug('[CreateOnboardingThreadJob] Creando hilo privado', [
            'channel_id'  => $channelId,
            'thread_name' => $threadName,
        ]);

        $createResponse = Http::withHeaders([
            'Authorization' => 'Bot ' . $betaToken,
            'Content-Type'  => 'application/json',
        ])->post("https://discord.com/api/v10/channels/{$channelId}/threads", [
            'name'                  => $threadName,
            'type'                  => 12,   // PRIVATE_THREAD
            'invitable'             => false, // solo el bot puede añadir miembros
            'auto_archive_duration' => 1440,  // 24 h de inactividad antes de archivar
        ]);

        if (! $createResponse->successful()) {
            Log::error('[CreateOnboardingThreadJob] Error al crear hilo privado', [
                'channel_id' => $channelId,
                'status'     => $createResponse->status(),
                'body'       => $createResponse->body(),
            ]);
            return;
        }

        $thread   = $createResponse->json();
        $threadId = $thread['id'];

        Log::info('[CreateOnboardingThreadJob] Hilo privado creado', [
            'thread_id'   => $threadId,
            'thread_name' => $threadName,
        ]);

        // ── 2. Añadir al player al hilo ───────────────────────────────────────
        $addMemberResponse = Http::withHeaders([
            'Authorization' => 'Bot ' . $betaToken,
        ])->put("https://discord.com/api/v10/channels/{$threadId}/thread-members/{$this->discordUserId}");

        if (! $addMemberResponse->successful()) {
            Log::warning('[CreateOnboardingThreadJob] No se pudo añadir al player al hilo', [
                'thread_id' => $threadId,
                'user_id'   => $this->discordUserId,
                'status'    => $addMemberResponse->status(),
                'body'      => $addMemberResponse->body(),
            ]);
        } else {
            Log::info('[CreateOnboardingThreadJob] Player añadido al hilo', [
                'thread_id' => $threadId,
                'user_id'   => $this->discordUserId,
            ]);
        }

        // ── 3. Mensaje inicial en el hilo etiquetando al player ───────────────
        $openingMessage = "👋 <@{$this->discordUserId}> ¡Bienvenido a tu sesión de entrevista para el Vault **{$this->vaultName}**!\n\nEn un momento el sistema comenzará la entrevista aquí. Este hilo es privado — solo tú y el bot podéis verlo.";

        $messageResponse = Http::withHeaders([
            'Authorization' => 'Bot ' . $betaToken,
            'Content-Type'  => 'application/json',
        ])->post("https://discord.com/api/v10/channels/{$threadId}/messages", [
            'content' => $openingMessage,
        ]);

        if (! $messageResponse->successful()) {
            Log::warning('[CreateOnboardingThreadJob] Error al enviar mensaje inicial al hilo', [
                'thread_id' => $threadId,
                'status'    => $messageResponse->status(),
                'body'      => $messageResponse->body(),
            ]);
        }

        // ── 4. Registrar sesión en cache para que el gateway monitoree el hilo ──
        Cache::put("thread_session_{$threadId}", [
            'discord_id' => $this->discordUserId,
            'guild_id'   => $this->discordGuildId,
        ], now()->addHours(48));

        // ── 5. Construir estado completo de entrevista (igual que handleInterviewStart) ──
        $fields = $agent->resolveFields($this->archetypeId, 'registration');

        $aiFieldTypes  = InterviewerAgent::AI_FIELD_TYPES;
        $aiFields      = array_values(array_filter($fields, fn($f) => in_array($f['field_type'] ?? 'text', $aiFieldTypes, true)));
        $formFields    = array_values(array_filter($fields, fn($f) => ! in_array($f['field_type'] ?? 'text', $aiFieldTypes, true)));

        $aiFieldKeys   = array_column($aiFields, 'field_key');
        $formFieldKeys = array_column($formFields, 'field_key');
        $requiredKeys  = array_values(array_column(array_filter($aiFields, fn($f) => $f['is_required']), 'field_key'));
        $optionalKeys  = array_values(array_column(array_filter($aiFields, fn($f) => ! $f['is_required']), 'field_key'));

        $player = Player::where('discord_id', $this->discordUserId)->first();

        Cache::put("interview_state_{$this->discordUserId}", [
            'archetype_id'          => $this->archetypeId,
            'interview_context'     => 'registration',
            'guild_id'              => $this->discordGuildId,
            'username'              => $this->username,
            'locale'                => $player?->preferred_locale ?? 'es',
            'turn'                  => 0,
            'status'                => 'in_progress',
            'is_edit'               => false,
            'thread_id'             => $threadId,
            'conversation_history'  => [],
            'extracted_fields'      => [],
            'ai_field_keys'         => $aiFieldKeys,
            'form_field_keys'       => $formFieldKeys,
            'required_field_keys'   => $requiredKeys,
            'optional_field_keys'   => $optionalKeys,
            'missing_required_keys' => $requiredKeys,
        ], now()->addMinutes(30));

        Log::info('[CreateOnboardingThreadJob] Hilo de onboarding listo y sesión registrada en cache', [
            'thread_id'    => $threadId,
            'guild_id'     => $this->discordGuildId,
            'user_id'      => $this->discordUserId,
            'vault'        => $this->vaultName,
            'archetype_id' => $this->archetypeId,
            'ai_fields'    => count($aiFieldKeys),
            'form_fields'  => count($formFieldKeys),
        ]);

        // ── 6. Despachar turno 0 para enviar la pregunta de apertura ─────────
        ProcessInterviewTurnJob::dispatch(
            discordId:  $this->discordUserId,
            token:      null,
            userAnswer: '',
            turn:       0,
            username:   $this->username,
            guildId:    $this->discordGuildId,
            threadId:   $threadId,
        );
    }

    private function resolveLocale(): string
    {
        $player = Player::where('discord_id', $this->discordUserId)->first();
        return $player?->preferred_locale ?? 'es';
    }
}
