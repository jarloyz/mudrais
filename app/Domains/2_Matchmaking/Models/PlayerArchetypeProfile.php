<?php

namespace App\Domains\Matchmaking\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Domains\Shared\Traits\HasOptimizedText;

class PlayerArchetypeProfile extends Model
{
    use \App\Traits\HasUuidV7;

    use HasOptimizedText, HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\PlayerArchetypeProfileFactory::new();
    }

    protected $keyType = 'string';

    protected $fillable = [
        'player_id',
        'discord_user_id', // @deprecated: usa player_id
        'archetype_id',
        'positive_prefs',
        'red_lines',
        'yellow_lines',
        'raw_profile',
        'preference_profile',
        'schedule',
        'schedule_raw',
        'metadata',
        'semantic_tag_query',
        'player_style_vector',
        'qdrant_id',
        'is_vectorized',
        'is_available',
        'content_raw',
        'indexing_status',
        'index_error',
    ];

    protected $casts = [
        'positive_prefs'      => 'array',
        'red_lines'           => 'array',
        'yellow_lines'        => 'array',
        'schedule'            => 'array',
        'metadata'            => 'array',
        'player_style_vector' => 'array',
        'is_vectorized'       => 'boolean',
        'is_available'        => 'boolean',
        'content_raw'         => 'array',
        'indexing_status'     => \App\Enums\IndexingStatus::class,
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Player::class);
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    public function scopeForUser(Builder $query, string $discordUserId): Builder
    {
        return $query->where('discord_user_id', $discordUserId);
    }

    public function scopeForArchetype(Builder $query, int $archetypeId): Builder
    {
        return $query->where('archetype_id', $archetypeId);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(\App\Models\CanonicalTag::class, 'taggable', 'taggables', 'taggable_id', 'canonical_tag_id')
            ->using(\App\Models\Taggable::class)
            ->withPivot('tag_context', 'original_text')
            ->withTimestamps();
    }

    public function tagsByContext(string $context): MorphToMany
    {
        return $this->tags()->wherePivot('tag_context', $context);
    }

    /**
     * Reemplaza todos los tags de un contexto con los IDs provistos.
     *
     * @param list<int> $tagIds
     */
    public function syncTags(array $tagIds, string $context): void
    {
        $this->tags()->wherePivot('tag_context', $context)->detach();

        foreach ($tagIds as $id) {
            $this->tags()->attach($id, ['tag_context' => $context]);
        }
    }

    /** Avatars que el jugador ha aceptado usar explícitamente. */
    public function avatars(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Domains\Narrative\Models\Avatar::class,
            'avatar_profile',
            'player_archetype_profile_id',
            'avatar_id'
        )->using(AvatarProfile::class)->withTimestamps();
    }
}
