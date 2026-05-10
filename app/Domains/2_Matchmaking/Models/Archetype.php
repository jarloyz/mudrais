<?php

namespace App\Domains\Matchmaking\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class Archetype extends Model
{
    use HasFactory, HasUuids;

    public function newUniqueId()
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory()
    {
        return \Database\Factories\ArchetypeFactory::new();
    }

    protected $fillable = [
        'id',
        'name',
        'slug',

        'summary',
        'qdrant_vector_name',
        'metadata_schema',
        'registration_modal_schema',
        'archetype_style_vector',
        'archetype_hub_qdrant_id',
        'is_hub_indexed',
        'search_weights',
    ];

    protected $casts = [
        'metadata_schema'           => 'array',
        'registration_modal_schema' => 'array',
        'archetype_style_vector'    => 'array',
        'is_hub_indexed'            => 'boolean',
        'search_weights'            => 'array',
    ];

    /**
     * Pesos por vector para la búsqueda multi-vector del Plan 2.
     * Configurable por arquetipo; fallback a valores por defecto si no está definido.
     */
    public function getSearchWeights(): array
    {
        return $this->search_weights ?? [
            'player_style'   => 0.60,
            'avatar_context' => 0.30,
            'activity_vibe'  => 0.10,
        ];
    }

    public function guilds(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Guild::class, 'archetype_guild')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(ArchetypePrompt::class);
    }

    public function mutators(): HasMany
    {
        return $this->hasMany(ArchetypeMutator::class)->orderBy('sort_order');
    }

    public function entityTypes(): HasMany
    {
        return $this->hasMany(ArchetypeEntityType::class)->orderBy('sort_order');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(\App\Models\CanonicalTag::class, 'taggable', 'taggables', 'taggable_id', 'canonical_tag_id')
            ->using(\App\Models\Taggable::class)
            ->withPivot('tag_context')
            ->withTimestamps();
    }

    public function getPromptFor(string $agentType): ?string
    {
        return $this->prompts->where('agent_type', $agentType)->first()?->system_prompt;
    }

    public function getMutatorsForContext(string $context): Collection
    {
        return $this->mutators->where('context', $context)->values();
    }

    public function getEntityTypesFor(string $entity): Collection
    {
        return $this->entityTypes->where('entity', $entity)->where('is_active', true)->values();
    }

    public function getMetadataSchema(): array
    {
        return $this->metadata_schema ?? [];
    }

    // Mantenido por compatibilidad con DiscordWebhookController hasta migrar a ArchetypeMutatorService.
    public function getModalDefinition(): array
    {
        return $this->registration_modal_schema ?? [];
    }
}
