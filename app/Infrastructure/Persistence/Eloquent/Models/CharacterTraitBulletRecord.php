<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterTraitBulletRecord extends Model
{
    protected $table = 'character_trait_bullets';

    protected $guarded = [];

    public function trait(): BelongsTo
    {
        return $this->belongsTo(CharacterTraitRecord::class, 'character_trait_id');
    }

    public function parentBullet(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_bullet_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_bullet_id')->orderBy('sort_order');
    }
}
