<?php

namespace App\Domains\Matchmaking\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class ArchetypeMutator extends Model
{
    use HasUuids;

    public function newUniqueId()
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'archetype_id',
        'archetype_entity_type_id',
        'context',
        'field_key',
        'field_label',
        'field_placeholder',
        'modal_group',
        'storage_mode',
        'field_type',
        'options',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options'      => 'array',
        'is_required'  => 'boolean',
        'storage_mode' => \App\Domains\Matchmaking\Enums\MutatorStorageMode::class,
    ];

    public function isInline(): bool
    {
        return blank($this->modal_group);
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    public function entityType(): BelongsTo
    {
        return $this->belongsTo(ArchetypeEntityType::class, 'archetype_entity_type_id');
    }

    public function scopeForContext(Builder $query, string $context): Builder
    {
        return $query->where('context', $context);
    }
}
