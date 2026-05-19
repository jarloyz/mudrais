<?php

namespace App\Jobs\Discord;

use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cuando el jugador confirma el resumen de la entrevista, enruta al job correcto
 * según el interview_context almacenado en el estado de sesión:
 *
 *   registration    → ProcessRegistroStep2Job
 *   avatar_context  → ProcessCreateContextJob
 *   activities_vibe → ProcessCreateActividadJob
 */
class ProcessInterviewAcceptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public int $timeout = 30;
    public int $tries   = 2;

    public function __construct(
        public readonly string  $discordId,
        public readonly string  $token,
        public readonly ?string $guildId,
        public readonly ?string $username,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('[ProcessInterviewAcceptJob] Inicio', [
            'discord_id' => $this->discordId,
            'guild_id'   => $this->guildId,
        ]);

        $state = Cache::get("interview_state_{$this->discordId}");

        if (! $state) {
            Log::warning('[ProcessInterviewAcceptJob] Estado no encontrado', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, __('discord.interview_expired'), [], true);
            return;
        }

        App::setLocale($state['locale'] ?? 'es');

        if (($state['status'] ?? '') !== 'awaiting_confirmation') {
            Log::warning('[ProcessInterviewAcceptJob] Estado inesperado', [
                'discord_id' => $this->discordId,
                'status'     => $state['status'] ?? null,
            ]);
            $this->sendFollowUp($this->token, __('discord.interview_expired'), [], true);
            return;
        }

        $player = Player::where('discord_id', $this->discordId)->first();

        if (! $player) {
            Log::error('[ProcessInterviewAcceptJob] Player no encontrado', ['discord_id' => $this->discordId]);
            $this->sendFollowUp($this->token, __('discord.user_not_found'), [], true);
            return;
        }

        $extracted        = $state['extracted_fields']  ?? [];
        $archetypeId      = $state['archetype_id']      ?? null;
        $isEdit           = (bool) ($state['is_edit']   ?? false);
        $interviewContext = $state['interview_context'] ?? 'registration';

        Cache::forget("interview_state_{$this->discordId}");

        Log::info('[ProcessInterviewAcceptJob] Enrutando por interview_context', [
            'discord_id'       => $this->discordId,
            'interview_context'=> $interviewContext,
            'archetype_id'     => $archetypeId,
            'extracted_count'  => count($extracted),
        ]);

        match ($interviewContext) {
            'avatar_context'  => $this->dispatchAvatarContext($extracted, $state),
            'activities_vibe' => $this->dispatchActivitiesVibe($extracted, $state),
            default           => $this->dispatchRegistration($extracted, $archetypeId, $isEdit, $player),
        };
    }

    private function dispatchRegistration(array $extracted, ?string $archetypeId, bool $isEdit, \App\Models\Player $player): void
    {
        // Preparar cache que ProcessRegistroStep2Job espera leer
        Cache::put("registro_step1_{$this->discordId}", [
            'is_edit'     => $isEdit,
            'nationality' => $player->nationality,
        ], now()->addMinutes(30));

        if ($archetypeId) {
            Cache::put("registro_archetype_{$this->discordId}", $archetypeId, now()->addMinutes(30));
        }

        Log::info('[ProcessInterviewAcceptJob] Despachando ProcessRegistroStep2Job', [
            'discord_id'   => $this->discordId,
            'archetype_id' => $archetypeId,
            'is_edit'      => $isEdit,
        ]);

        ProcessRegistroStep2Job::dispatch(
            $this->discordId,
            $extracted,
            $this->token,
            $this->guildId,
            $this->username,
        );
    }

    private function dispatchAvatarContext(array $extracted, array $state): void
    {
        $vaultId      = $state['vault_id']       ?? null;
        $entityTypeId = $state['entity_type_id'] ?? null;
        $contextName  = $extracted['context_name'] ?? '';

        Log::info('[ProcessInterviewAcceptJob] Despachando ProcessCreateContextJob', [
            'discord_id'     => $this->discordId,
            'vault_id'       => $vaultId,
            'entity_type_id' => $entityTypeId,
            'context_name'   => $contextName,
        ]);

        \App\Jobs\Discord\ProcessCreateContextJob::dispatch(
            $this->token,
            $this->discordId,
            $vaultId,
            $entityTypeId,
            $contextName,
            $extracted,
        );
    }

    private function dispatchActivitiesVibe(array $extracted, array $state): void
    {
        $vaultId        = $state['vault_id']          ?? null;
        $activityTypeId = $state['activity_type_id']  ?? null;
        $ctx1Id         = $state['ctx1_id']            ?? null;
        $ctx2Id         = $state['ctx2_id']            ?? null;
        $titulo         = $extracted['titulo']         ?? '';
        $extraContext   = $extracted['extra_context']  ?? '';

        Log::info('[ProcessInterviewAcceptJob] Despachando ProcessCreateActividadJob', [
            'discord_id'       => $this->discordId,
            'vault_id'         => $vaultId,
            'activity_type_id' => $activityTypeId,
            'titulo'           => $titulo,
        ]);

        \App\Jobs\Discord\ProcessCreateActividadJob::dispatch(
            $this->token,
            $this->discordId,
            $vaultId,
            $activityTypeId,
            $titulo,
            $extraContext,
            $ctx1Id,
            $ctx2Id,
        );
    }
}
