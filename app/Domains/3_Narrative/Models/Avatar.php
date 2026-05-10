<?php

namespace App\Domains\Narrative\Models;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\AvatarProfile;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Domains\Shared\Traits\HasOptimizedText;

class Avatar extends Model
{
    use \App\Traits\HasUuidV7;
    use HasFactory, HasOptimizedText;

    protected $table = 'avatars';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'name',
        'vault_id',
        'owner_profile_id',
        'avatar_context_vector',
        'avatar_hub_qdrant_id',
        'is_hub_indexed',
        'indexing_status',
        'index_error',
        'is_lfg',
        'content_raw',
        'semantic_tag_query',
        'archetype_entity_type_id',
        'discord_thread_id',
    ];

    protected $casts = [
        'avatar_context_vector' => 'array',
        'is_hub_indexed'        => 'boolean',
        'is_lfg'                => 'boolean',
        'content_raw'           => 'array',
        'indexing_status'       => \App\Enums\IndexingStatus::class,
    ];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function entityType(): BelongsTo
    {
        return $this->belongsTo(ArchetypeEntityType::class, 'archetype_entity_type_id');
    }

    public function ownerProfile(): BelongsTo
    {
        return $this->belongsTo(PlayerArchetypeProfile::class, 'owner_profile_id');
    }

    public function bullets(): HasMany
    {
        return $this->hasMany(\App\Models\CharacterBullet::class, 'character_id');
    }

    public function backgrounds(): HasMany
    {
        return $this->hasMany(\App\Models\CharacterBackground::class, 'character_id');
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(\App\Models\CanonicalTag::class, 'taggable', 'taggables', 'taggable_id', 'canonical_tag_id')
            ->using(\App\Models\Taggable::class)
            ->withPivot('tag_context', 'original_text')
            ->withTimestamps();
    }

    /** Actividades en las que este avatar participa. */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'activity_members', 'avatar_id', 'activity_id')
            ->withPivot(['role', 'controlled_by_player_id', 'scene_role', 'initiative_score', 'has_acted_this_round'])
            ->withTimestamps();
    }

    public function controlledBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Player::class, 'controlled_by_player_id');
    }

    /** Perfiles que han aceptado usar este avatar explícitamente. */
    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(
            PlayerArchetypeProfile::class,
            'avatar_profile',
            'avatar_id',
            'player_archetype_profile_id'
        )->using(AvatarProfile::class)->withTimestamps();
    }
}
