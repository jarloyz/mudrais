<?php

namespace App\Domains\Matchmaking\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchetypeEntityType extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'archetype_id',
        'entity',
        'avatar_purpose',
        'type_key',
        'type_label',
        'description',
        'system_prompt',
        'matching_filters',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'matching_filters' => 'array',
    ];

    public function getMutatorContext(): string
    {
        return match($this->entity) {
            'activity' => 'activities_vibe',
            'avatar'   => 'avatar_context',
            default    => 'registration',
        };
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    public function scopeForEntity(Builder $query, string $entity): Builder
    {
        return $query->where('entity', $entity);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('avatar_purpose', $purpose);
    }
}
