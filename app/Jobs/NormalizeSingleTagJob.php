<?php

namespace App\Jobs;

use App\Application\Services\TagNormalizerService;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Avatar;
use App\Models\CanonicalTag;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Normaliza un único término en un CanonicalTag y lo adjunta a un Avatar o PlayerArchetypeProfile.
 *
 * NormalizeAvatarTagsJob y NormalizePlayerTagsJob actúan como orchestrators que despachan
 * un NormalizeSingleTagJob por cada término. Esto mantiene cada job bajo los ~15s
 * independientemente de cuántos tags tenga un avatar o perfil.
 */
class NormalizeSingleTagJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(
        public readonly ?string $avatarId,
        public readonly ?string $profileId,
        public readonly string  $term,
        public readonly string  $tagContext,
        public readonly ?string $originalText = null,
        public readonly ?string $playerId     = null,
        public readonly ?string $archetypeId  = null,
    ) {
        $this->onQueue('tags');
    }

    public function handle(TagNormalizerService $normalizer): void
    {
        Log::debug('NormalizeSingleTagJob: inicio.', [
            'avatar_id'    => $this->avatarId,
            'profile_id'   => $this->profileId,
            'term'         => $this->term,
            'tag_context'  => $this->tagContext,
            'archetype_id' => $this->archetypeId,
        ]);

        $tag = $normalizer->normalizeTag($this->term, $this->playerId, $this->archetypeId);

        if (! ($tag instanceof CanonicalTag)) {
            Log::warning('NormalizeSingleTagJob: no se pudo normalizar el término.', [
                'term'       => $this->term,
                'avatar_id'  => $this->avatarId,
                'profile_id' => $this->profileId,
            ]);
            return;
        }

        $pivotData = [
            'tag_context'   => $this->tagContext,
            'original_text' => $this->originalText ?? $this->term,
        ];

        if ($this->avatarId !== null) {
            $this->attachToAvatar($tag, $pivotData);
        } elseif ($this->profileId !== null) {
            $this->attachToProfile($tag, $pivotData);
        }
    }

    private function attachToAvatar(CanonicalTag $tag, array $pivotData): void
    {
        $avatar = Avatar::find($this->avatarId);
        if (! $avatar) {
            Log::warning('NormalizeSingleTagJob: avatar no encontrado.', ['avatar_id' => $this->avatarId]);
            return;
        }

        $avatar->tags()->syncWithoutDetaching([$tag->id => $pivotData]);

        Log::debug('NormalizeSingleTagJob: tag adjuntado a avatar.', [
            'avatar_id' => $this->avatarId,
            'term'      => $this->term,
            'slug'      => $tag->slug,
        ]);
    }

    private function attachToProfile(CanonicalTag $tag, array $pivotData): void
    {
        $profile = PlayerArchetypeProfile::find($this->profileId);
        if (! $profile) {
            Log::warning('NormalizeSingleTagJob: perfil no encontrado.', ['profile_id' => $this->profileId]);
            return;
        }

        $profile->tags()->syncWithoutDetaching([$tag->id => $pivotData]);

        Log::debug('NormalizeSingleTagJob: tag adjuntado a perfil.', [
            'profile_id' => $this->profileId,
            'term'       => $this->term,
            'slug'       => $tag->slug,
            'context'    => $pivotData['tag_context'],
        ]);
    }
}
