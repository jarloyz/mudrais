<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterTraitRecord extends Model
{
    protected $table = 'character_traits';

    protected $guarded = [];

    public function character(): BelongsTo
    {
        return $this->belongsTo(CharacterRecord::class, 'character_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(ContextRecord::class, 'context_id');
    }

    public function bullets(): HasMany
    {
        return $this->hasMany(CharacterTraitBulletRecord::class, 'character_trait_id')->orderBy('sort_order');
    }
}
