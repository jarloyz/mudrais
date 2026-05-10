<?php

namespace App\Jobs\Discord;

use App\Application\Services\TagNormalizerService;
use App\Infrastructure\Ai\Agents\VaultOptimizerAgent;
use App\Infrastructure\Discord\Embeds\VaultApprovalEmbeds;
use App\Models\Archetype;
use App\Services\Discord\Contracts\DiscordWebhookClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessVaultOnboardingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        private string $token,
        private string $guildId,
        private string $archetypeId,
        private string $name,
        private string $description,
        private array $metadata = [],
        private ?string $channelId = null
    ) {}

    public function handle(
        VaultOptimizerAgent $optimizerAgent,
        TagNormalizerService $tagNormalizer,
        DiscordWebhookClient $webhookClient,
    ): void {
        Log::info('[ProcessVaultOnboardingJob] Iniciando optimización de Vault', [
            'guild_id' => $this->guildId,
            'channel_id' => $this->channelId,
            'name'     => $this->name,
        ]);

        $fullDescription = $this->description;
        if (!empty($this->metadata)) {
            $fullDescription .= "\n\n**Detalles Adicionales:**\n";
            foreach ($this->metadata as $key => $value) {
                $fullDescription .= "- {$key}: {$value}\n";
            }
        }

        try {
            $archetype = $this->archetypeId ? Archetype::find($this->archetypeId) : null;
            $optimized = $optimizerAgent->optimize($this->name, $fullDescription, $archetype);

            $phrases = array_slice(
                array_filter(array_map('trim', explode(',', $optimized['semantic_tag_query'])), fn($s) => $s !== ''),
                0, 5
            );

            $tags = $tagNormalizer->normalizeBatch($phrases);
            $tagIds = array_map(fn($t) => $t->id, $tags);
            $tagSlugs = array_map(fn($t) => $t->slug, $tags);

            $pendingId = (string) Str::uuid();
            $payload = [
                'guild_id'     => $this->guildId,
                'channel_id'   => $this->channelId,
                'archetype_id' => $this->archetypeId,
                'name'         => $this->name,
                'description'  => $fullDescription,
                'optimized'    => $optimized,
                'tag_ids'      => $tagIds,
            ];
            Cache::put("vault_pending_{$pendingId}", $payload, now()->addMinutes(15));

            $webhookClient->sendFollowUp(
                $this->token,
                '',
                VaultApprovalEmbeds::approvalRequest($optimized, $tagSlugs, $pendingId),
                true // ephemeral
            );

            Log::info('[ProcessVaultOnboardingJob] Optimización completada, esperando aprobación', [
                'pending_id' => $pendingId,
                'guild_id'   => $this->guildId,
            ]);

        } catch (\Throwable $e) {
            Log::error('[ProcessVaultOnboardingJob] Error en optimización de Vault', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $webhookClient->sendFollowUp(
                $this->token,
                "❌ **Error al preparar el Vault:** {$e->getMessage()}",
                [],
                true
            );
        }
    }
}
