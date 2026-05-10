<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoryContext extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'label',
        'legacy_context_id',
        'description',
    ];

    public function characterTraits(): HasMany
    {
        return $this->hasMany(CharacterTrait::class, 'context_id');
    }
}
