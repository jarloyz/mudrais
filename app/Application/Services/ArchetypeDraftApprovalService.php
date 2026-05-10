<?php

namespace App\Application\Services;

use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeDraft;
use App\Jobs\IndexArchetypeJob;
use App\Models\CanonicalTag;
use Illuminate\Support\Facades\DB;

class ArchetypeDraftApprovalService
{
    public function __construct(
        private TagNormalizerService $tagNormalizer
    ) {}

    public function approve(ArchetypeDraft $draft, ?string $adminId = null): Archetype
    {
        $archetype = DB::transaction(function () use ($draft, $adminId) {
            $archetype = Archetype::create([
                'name'               => $draft->name_en,
                'slug'               => $draft->slug,
                'summary'            => $draft->optimized_text_en,
                'qdrant_vector_name' => str_replace('-', '_', $draft->slug),
            ]);

            $tagIds = [];

            $suggestedTags = $draft->suggested_tags ?? [];

            foreach ($suggestedTags as $tagData) {
                if (($tagData['source'] ?? '') === 'qdrant' && !empty($tagData['canonical_tag_id'])) {
                    $tagIds[] = (string) $tagData['canonical_tag_id'];
                } elseif (($tagData['source'] ?? '') === 'ai_proposal' && !empty($tagData['slug'])) {
                    $tag = CanonicalTag::firstOrCreate(
                        ['slug' => $tagData['slug']],
                        [
                            'name'        => $tagData['name'] ?? $tagData['slug'],
                            'description' => $tagData['description'] ?? '',
                            'is_active'   => true,
                        ]
                    );

                    if ($tag->wasRecentlyCreated) {
                        $this->tagNormalizer->indexExistingTag($tag);
                    }

                    $tagIds[] = $tag->id;
                }
            }

            if (!empty($tagIds)) {
                $syncData = [];
                foreach ($tagIds as $id) {
                    $syncData[$id] = ['tag_context' => 'general'];
                }
                $archetype->tags()->sync($syncData);
            }

            $draft->update([
                'status'       => ArchetypeDraftStatus::APPROVED->value,
                'archetype_id' => $archetype->id,
                'reviewed_by'  => $adminId,
                'reviewed_at'  => now(),
            ]);

            return $archetype;
        });

        IndexArchetypeJob::dispatch($archetype->id);

        return $archetype;
    }
}
