<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CanonicalTag extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = ['slug', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function profiles(): MorphToMany
    {
        return $this->morphedByMany(PlayerArchetypeProfile::class, 'taggable', 'taggables', 'canonical_tag_id', 'taggable_id')
            ->using(Taggable::class)
            ->withPivot('tag_context', 'original_text')
            ->withTimestamps();
    }

    public function avatars(): MorphToMany
    {
        return $this->morphedByMany(\App\Domains\Narrative\Models\Avatar::class, 'taggable', 'taggables', 'canonical_tag_id', 'taggable_id')
            ->using(Taggable::class)
            ->withPivot('tag_context', 'original_text')
            ->withTimestamps();
    }

    public function activities(): MorphToMany
    {
        return $this->morphedByMany(\App\Domains\Narrative\Models\Activity::class, 'taggable', 'taggables', 'canonical_tag_id', 'taggable_id')
            ->using(Taggable::class)
            ->withPivot('tag_context', 'original_text')
            ->withTimestamps();
    }
}
