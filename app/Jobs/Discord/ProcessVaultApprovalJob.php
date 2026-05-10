<?php

namespace App\Jobs\Discord;

use App\Infrastructure\Discord\Embeds\VaultApprovalEmbeds;
use App\Jobs\IndexVaultJob;
use App\Services\Discord\VaultOnboardingService;
use App\Services\Discord\Contracts\DiscordWebhookClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\Uuid;

class ProcessVaultApprovalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $pendingId,
        private string $token,
        private string $discordId,
    ) {}

    public function handle(
        VaultOnboardingService $service,
        DiscordWebhookClient $webhookClient
    ): void {
        Log::info('[ProcessVaultApprovalJob] Iniciando aprobación de Vault', [
            'pending_id' => $this->pendingId,
            'discord_id' => $this->discordId,
        ]);

        $cacheKey = "vault_pending_{$this->pendingId}";
        $pending = Cache::get($cacheKey);

        if (! $pending) {
            Log::warning('[ProcessVaultApprovalJob] Sesión expirada o no encontrada', [
                'pending_id' => $this->pendingId,
            ]);
            $webhookClient->sendFollowUp(
                $this->token,
                '⚠️ **Sesión expirada.** Por favor, vuelve a intentar la creación del Vault.'
            );
            return;
        }

        try {
            $vault = $service->createVault([
                'guild_id'     => $pending['guild_id'],
                'archetype_id' => $pending['archetype_id'],
                'name'         => $pending['optimized']['name_es'],
                'description'  => $pending['optimized']['optimized_text_en'],
                'channel_id'   => $pending['channel_id'] ?? null,
            ]);

            if (! $vault) {
                throw new \RuntimeException('El servicio retornó null al crear el Vault.');
            }

            if (!empty($pending['tag_ids'])) {
                $tagData = [];
                $now = now();
                foreach ($pending['tag_ids'] as $tagId) {
                    $tagData[] = [
                        'id'               => (string) Uuid::v7(),
                        'taggable_id'      => $vault->id,
                        'taggable_type'    => get_class($vault),
                        'canonical_tag_id' => $tagId,
                        'tag_context'      => 'content',
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ];
                }
                DB::table('taggables')->insertOrIgnore($tagData);
            }

            IndexVaultJob::dispatch($vault->id);

            Cache::forget($cacheKey);

            $webhookClient->sendFollowUp(
                $this->token,
                '',
                VaultApprovalEmbeds::approved($vault->id)
            );

            Log::info('[ProcessVaultApprovalJob] Vault creado y aprobado exitosamente', [
                'vault_id' => $vault->id,
            ]);

        } catch (\Throwable $e) {
            Log::error('[ProcessVaultApprovalJob] Error al crear Vault tras aprobación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $webhookClient->sendFollowUp(
                $this->token,
                "❌ **Error interno al crear el Vault:** {$e->getMessage()}"
            );
        }
    }
}
