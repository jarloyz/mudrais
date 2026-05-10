<?php

namespace App\Domains\Narrative\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Domains\Shared\Traits\HasOptimizedText;
use App\Models\CanonicalTag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Vault extends Model
{
    use \App\Traits\HasUuidV7;
    use HasFactory, HasOptimizedText;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'discord_channel_id',
        'name',
        'status',
        'description',
        'world_notes',
        'agent_instructions',
        'vault_setting_vector',
        'vault_hub_qdrant_id',
        'is_hub_indexed',
        'guild_id',
        'is_public',
        'discord_context_channel_id',
        'discord_activity_channel_id',
    ];

    public function archetypes(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Archetype::class, 'archetype_vault')
            ->withPivot(['is_primary', 'guild_id'])
            ->withTimestamps();
    }

    /**
     * Relación de conveniencia para obtener el arquetipo primario (singular).
     */
    public function archetype()
    {
        return $this->archetypes()->wherePivot('is_primary', true);
    }

    public function primaryArchetype(): ?\App\Models\Archetype
    {
        return $this->archetypes()->wherePivot('is_primary', true)->first();
    }

    protected $casts = [
        'world_notes'          => 'array',
        'agent_instructions'   => 'array',
        'vault_setting_vector' => 'array',
        'is_hub_indexed'       => 'boolean',
        'is_public'            => 'boolean',
    ];

    public function tags(): MorphToMany
    {
        return $this->morphToMany(CanonicalTag::class, 'taggable', 'taggables', 'taggable_id', 'canonical_tag_id')
            ->using(\App\Models\Taggable::class)
            ->withPivot('tag_context', 'original_text')
            ->withTimestamps();
    }

    public function guild(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Community\Models\Guild::class);
    }

    public function quests(): HasMany
    {
        return $this->hasMany(\App\Models\Quest::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(\App\Models\Event::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(\App\Models\Location::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(\App\Models\VaultPlayerMembership::class);
    }

    public function players(): BelongsToMany
    {
        // Cross-domain: Player pertenece a Community.
        return $this->belongsToMany(\App\Models\Player::class, 'vault_player_memberships')
            ->using(\App\Models\VaultPlayerMembership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Avatar::class);
    }
}
