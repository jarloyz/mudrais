<?php

namespace App\Jobs;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use App\Domains\Matchmaking\Models\ArchetypeDraft;
use App\Infrastructure\Ai\Prompts\ArchetypeTagProposalPrompt;
use App\Support\UserAiSettingsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateArchetypeTagProposalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public string $draftId
    ) {}

    public function handle(
        AiChatGateway $chatGateway,
        UserAiSettingsResolver $settingsResolver
    ): void {
        $draft = ArchetypeDraft::find($this->draftId);

        if (! $draft) {
            Log::warning('[GenerateArchetypeTagProposalsJob] Draft no encontrado.', ['draft_id' => $this->draftId]);
            return;
        }

        if ($draft->status !== ArchetypeDraftStatus::NEEDS_REVIEW) {
            Log::info('[GenerateArchetypeTagProposalsJob] Draft no está NEEDS_REVIEW, saltando.', [
                'draft_id' => $this->draftId,
                'status' => $draft->status->value
            ]);
            return;
        }

        $draft->update(['status' => ArchetypeDraftStatus::PROCESSING->value]);

        try {
            $existingSlugs = array_column($draft->suggested_tags ?? [], 'slug');

            $model = $settingsResolver->resolveAgentModel(null, 'optimizer');

            if (empty($model)) {
                throw new \RuntimeException('Modelo optimizer no configurado para propuestas de tags.');
            }

            $prompt = ArchetypeTagProposalPrompt::getPrompt($draft->optimized_text_en, $existingSlugs);

            $response = $chatGateway->chat($model, [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => 'Propose tags for this archetype in the required JSON array format.'],
            ], 0.2, 500);

            $rawJson = trim($response['text'] ?? '');
            $rawJson = preg_replace('/^```(?:json)?\s*/i', '', $rawJson) ?? $rawJson;
            $rawJson = preg_replace('/```\s*$/i', '', $rawJson) ?? $rawJson;
            $rawJson = trim($rawJson);

            if ($rawJson === '') {
                throw new \RuntimeException('Respuesta vacía del LLM al generar propuestas de tags.');
            }

            $proposals = json_decode($rawJson, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($proposals)) {
                throw new \RuntimeException('Error parseando JSON de propuestas de tags.');
            }

            $mappedProposals = array_map(fn($p) => [
                'source'           => 'ai_proposal',
                'canonical_tag_id' => null,
                'slug'             => $p['slug'] ?? null,
                'name'             => $p['name'] ?? null,
                'description'      => $p['description'] ?? null,
                'score'            => null,
            ], $proposals);

            $mappedProposals = array_filter($mappedProposals, fn($p) => !empty($p['slug']) && !empty($p['name']));

            $mergedTags = $draft->suggested_tags ?? [];
            foreach ($mappedProposals as $proposal) {
                if (!in_array($proposal['slug'], $existingSlugs, true)) {
                    $mergedTags[] = $proposal;
                    $existingSlugs[] = $proposal['slug'];
                }
            }

            $draft->update([
                'suggested_tags'   => array_values($mergedTags),
                'status'           => ArchetypeDraftStatus::NEEDS_REVIEW->value,
                'processing_error' => null,
            ]);

            Log::info('[GenerateArchetypeTagProposalsJob] Propuestas generadas exitosamente.', ['draft_id' => $draft->id]);
        } catch (\Throwable $e) {
            $draft->update([
                'status'           => ArchetypeDraftStatus::NEEDS_REVIEW->value,
                'processing_error' => $e->getMessage(),
            ]);
            Log::error('[GenerateArchetypeTagProposalsJob] Error al generar propuestas.', [
                'draft_id' => $draft->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
