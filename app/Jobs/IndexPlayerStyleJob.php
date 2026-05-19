<?php

namespace App\Jobs;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Services\QdrantService;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Models\PlayerArchetypeProfile;
use App\Enums\IndexingStatus;
use App\Infrastructure\Ai\Agents\ProfileOptimizerAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexPlayerStyleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly string $playerArchetypeProfileId
    ) {
        $this->onQueue('index');
    }

    public function handle(
        EmbeddingGateway $gateway,
        QdrantService $qdrant,
        UserAiSettingsResolver $resolver,
        ProfileOptimizerAgent $optimizer,
        ArchetypeMutatorService $mutatorService,
    ): void {
        Log::info("IndexPlayerStyleJob: Iniciando procesamiento para profile {$this->playerArchetypeProfileId}");

        $profile = PlayerArchetypeProfile::with(['player', 'tags', 'optimizable', 'archetype'])->find($this->playerArchetypeProfileId);

        if (! $profile || ! $profile->player) {
            Log::warning("IndexPlayerStyleJob: profile {$this->playerArchetypeProfileId} o su player no encontrados.");
            return;
        }

        $player       = $profile->player;
        $originalText = $this->buildProfileText($player, $profile, $mutatorService);

        if ($originalText === '') {
            Log::warning("IndexPlayerStyleJob: profile {$profile->id} sin datos para indexar.");
            $profile->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'Sin texto para indexar: perfil, red_lines, yellow_lines y bio vacíos',
            ]);
            return;
        }

        Log::debug("IndexPlayerStyleJob: Texto original construido (length: " . strlen($originalText) . ")");

        // Prefer pre-generated text from ProcessRegistroStep2Job (saved in optimizables table).
        // Only run ProfileOptimizerAgent when no pre-generated text exists (e.g. admin-triggered re-index).
        $preOptimized = $profile->getOptimizedText();
        if ($preOptimized !== null && trim($preOptimized) !== '') {
            $textForEmbedding = $preOptimized;
            Log::debug('IndexPlayerStyleJob: Usando texto pre-optimizado de optimizables.', [
                'profile_id' => $profile->id,
                'length'     => mb_strlen($textForEmbedding),
            ]);
        } else {
            $archetype = $profile->archetype instanceof Archetype ? $profile->archetype : null;
            try {
                $result           = $optimizer->optimize($originalText, $archetype, (string) $player->id);
                $textForEmbedding = $result['optimized_text'];
                Log::debug('IndexPlayerStyleJob: Texto optimizado por ProfileOptimizerAgent.', [
                    'profile_id' => $profile->id,
                    'length'     => mb_strlen($textForEmbedding),
                ]);
            } catch (\RuntimeException $e) {
                Log::error('IndexPlayerStyleJob: optimizer falló — job abortado, perfil NO indexado.', [
                    'profile_id' => $profile->id,
                    'error'      => $e->getMessage(),
                ]);
                $profile->update([
                    'indexing_status' => IndexingStatus::Failed,
                    'index_error'     => '[ProfileOptimizer] ' . $e->getMessage(),
                ]);
                return;
            }
        }

        $model = $resolver->resolveAgentModel($player->id ?? 0, 'embedding');
        Log::debug("IndexPlayerStyleJob: Usando modelo embedding: {$model}");

        $vector = $gateway->embed($model, $textForEmbedding);

        if (empty($vector)) {
            Log::error("IndexPlayerStyleJob: embedding failed for profile {$profile->id}.");
            $profile->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'Embedding vacío — fallo en EmbeddingGateway',
            ]);
            return;
        }

        Log::debug("IndexPlayerStyleJob: Vector obtenido (dimensiones: " . count($vector) . ")");

        $guildIds = $player->guilds()->pluck('guilds.id')->all();
        $qdrantId = $profile->qdrant_id ?: (string) Str::uuid();

        // Tags como IDs de canonical_tags agrupados por contexto.
        // Las strings ya están capturadas en el vector (buildProfileText);
        // el payload usa IDs enteros para filtros eficientes en Qdrant.
        $tagsByContext = $profile->tags->groupBy(fn($t) => $t->pivot->tag_context);

        // Campos base del formulario — ya representados como tag IDs o en el vector.
        // Solo los campos adicionales (mutadores del arquetipo) van en metadata.
        $baseFields    = ['red_lines', 'yellow_lines', 'preferences', 'style'];
        $extraMetadata = array_diff_key((array) ($profile->metadata ?? []), array_flip($baseFields));

        $payload = [
            'entity_type'       => 'player_profile',
            'player_profile_id' => $profile->id,
            'guild_ids'         => $guildIds,
            'archetype_id'      => $profile->archetype_id,
            'is_available'      => (bool) ($profile->is_available ?? true),
            'red_line_ids'      => $tagsByContext->get('red_line', collect())->pluck('id')->values()->all(),
            'yellow_line_ids'   => $tagsByContext->get('yellow_line', collect())->pluck('id')->values()->all(),
            'preference_ids'    => $tagsByContext->get('preference', collect())->pluck('id')->values()->all(),
            'metadata'          => $extraMetadata,
        ];

        Log::debug("IndexPlayerStyleJob: Enviando a Qdrant (ID: {$qdrantId})");

        $profile->update(['indexing_status' => IndexingStatus::Processing]);

        try {
            $success = $qdrant->upsertHubPoint($qdrantId, ['player_style' => $vector], $payload);
        } catch (\Throwable $e) {
            Log::error('[IndexPlayerStyleJob] Excepción durante upsert a Qdrant.', [
                'profile_id' => $profile->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            $profile->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => '[upsert exception] ' . $e->getMessage(),
            ]);
            return;
        }

        if ($success) {
            Log::debug("IndexPlayerStyleJob: Upsert en Qdrant exitoso, actualizando DB.");
            $profile->update([
                'qdrant_id'          => $qdrantId,
                'is_vectorized'      => true,
                'player_style_vector' => $vector,
                'indexing_status'    => IndexingStatus::Indexed,
                'index_error'        => null,
            ]);
            Log::info("IndexPlayerStyleJob: profile {$profile->id} indexed in matchmaking_hub.");
        } else {
            Log::error("IndexPlayerStyleJob: Qdrant sync failed for profile {$profile->id}.");
            $profile->update([
                'indexing_status' => IndexingStatus::Failed,
                'index_error'     => 'Qdrant upsertHubPoint devolvió false',
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        PlayerArchetypeProfile::find($this->playerArchetypeProfileId)?->update([
            'indexing_status' => IndexingStatus::Failed,
            'index_error'     => '[crash] ' . $exception->getMessage(),
        ]);

        Log::error('[IndexPlayerStyleJob] Job terminado por excepción no controlada', [
            'profile_id' => $this->playerArchetypeProfileId,
            'error'      => $exception->getMessage(),
        ]);
    }

    private function buildProfileText(Player $player, PlayerArchetypeProfile $profile, ArchetypeMutatorService $mutatorService): string
    {
        $parts    = [];
        $metadata = (array) ($profile->metadata ?? []);

        $style = trim((string) ($profile->preference_profile ?? $profile->raw_profile ?? ''));
        if ($style !== '') {
            $parts[] = "Style: {$style}.";
        }

        $redLines = array_filter(array_map('trim', (array) ($profile->red_lines ?? [])));
        if (! empty($redLines)) {
            $parts[] = 'Hard limits: ' . implode(', ', $redLines) . '.';
        }

        $yellowLines = array_filter(array_map('trim', (array) ($profile->yellow_lines ?? [])));
        if (! empty($yellowLines)) {
            $parts[] = 'Soft limits: ' . implode(', ', $yellowLines) . '.';
        }

        $prefs = array_filter(array_map('trim', (array) ($profile->positive_prefs ?? [])));
        if (! empty($prefs)) {
            $parts[] = 'Affinities: ' . implode(', ', $prefs) . '.';
        }

        // Include ALL semantic mutator fields using archetype definitions for proper labels.
        // Falls back to two hardcoded legacy fields when no archetype is available.
        if ($profile->archetype_id !== null) {
            $mutators = $mutatorService->getFieldsForContext($profile->archetype_id, 'registration');
            foreach ($mutators->filter(fn ($m) => $m->storage_mode->storesSemantic()) as $m) {
                $value = $metadata[$m->field_key] ?? null;
                if ($value !== null && trim((string) $value) !== '') {
                    $parts[] = "{$m->field_label}: {$value}.";
                }
            }
        } else {
            if (($expLevel = $metadata['experience_level'] ?? null) !== null) {
                $parts[] = "Experience level: {$expLevel}/5.";
            }
            if (($verbLevel = $metadata['verbosity_level'] ?? null) !== null) {
                $parts[] = "Verbosity: {$verbLevel}/5.";
            }
        }

        $bio = trim((string) ($player->about_me ?? ''));
        if ($bio !== '') {
            $parts[] = "Bio: {$bio}.";
        }

        return implode("\n", $parts);
    }
}
