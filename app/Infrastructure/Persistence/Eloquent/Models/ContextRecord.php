<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContextRecord extends Model
{
    protected $table = 'story_contexts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function characterTraits(): HasMany
    {
        return $this->hasMany(CharacterTraitRecord::class, 'context_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(LocationRecord::class, 'context_id');
    }
}
