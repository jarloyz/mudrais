<?php

namespace App\Domains\Narrative\Models;

use App\Domains\Matchmaking\Enums\ActivitySearchDirection;
use App\Domains\Matchmaking\Enums\ActivityStatus;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Domains\Shared\Traits\HasOptimizedText;

class Activity extends Model
{
    use \App\Traits\HasUuidV7;
    use HasFactory, HasOptimizedText;

    protected $table = 'activities';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $casts = [
        'status'           => ActivityStatus::class,
        'search_direction' => ActivitySearchDirection::class,
        'is_hub_indexed'   => 'boolean',
        'requires_avatar'  => 'boolean',
        'content_raw'      => 'array',
        'indexing_status'  => \App\Enums\IndexingStatus::class,
    ];

    protected $fillable = [
        'id',
        'vault_id',
        'creator_profile_id',
        'activity_hub_qdrant_id',
        'is_hub_indexed',
        'indexing_status',
        'index_error',
        'requires_avatar',
        'activity_description',
        'title',
        'chapter',
        'scene_number',
        'status',
        'location_id',
        'objective',
        'content_raw',
        'semantic_tag_query',
        'constraints',
        'draft',
        'current_turn_character_id',
        'round_number',
        'archetype_entity_type_id',
        'discord_thread_id',
        'search_direction',
        'parent_activity_id',
        'required_slots',
    ];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function entityType(): BelongsTo
    {
        return $this->belongsTo(ArchetypeEntityType::class, 'archetype_entity_type_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(PlayerArchetypeProfile::class, 'creator_profile_id');
    }

    /** Avatars (personajes) que participan en esta actividad con avatar. */
    public function avatars(): BelongsToMany
    {
        return $this->belongsToMany(Avatar::class, 'activity_members', 'activity_id', 'avatar_id')
            ->withPivot(['role', 'controlled_by_player_id', 'scene_role', 'initiative_score', 'has_acted_this_round'])
            ->withTimestamps();
    }

    /** Perfiles de arquetipo que participan sin avatar (requires_avatar = false). */
    public function profileMembers(): BelongsToMany
    {
        return $this->belongsToMany(
            PlayerArchetypeProfile::class,
            'activity_members',
            'activity_id',
            'player_archetype_profile_id'
        )->withPivot(['role', 'scene_role'])->withTimestamps();
    }

    public function activeContinuity(): HasOne
    {
        return $this->hasOne(\App\Models\SceneActiveContinuity::class, 'activity_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** Actividad padre en una búsqueda de equipo. */
    public function parentActivity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'parent_activity_id');
    }

    /** Slots hijo que componen esta búsqueda de equipo. */
    public function childActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'parent_activity_id');
    }

    public function isTeamSearch(): bool
    {
        return $this->required_slots !== null;
    }

    public function isInbound(): bool
    {
        return in_array($this->search_direction, [
            ActivitySearchDirection::INBOUND,
            ActivitySearchDirection::BOTH,
        ], strict: true);
    }

    public function isOutbound(): bool
    {
        return in_array($this->search_direction, [
            ActivitySearchDirection::OUTBOUND,
            ActivitySearchDirection::BOTH,
        ], strict: true);
    }

    public function canonicalTags(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(\App\Models\CanonicalTag::class, 'taggable', 'taggables', 'taggable_id', 'canonical_tag_id')
            ->using(\App\Models\Taggable::class)
            ->withPivot('tag_context', 'original_text')
            ->withTimestamps();
    }
}
