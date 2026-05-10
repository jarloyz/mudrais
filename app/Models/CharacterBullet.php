<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterBullet extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'character_id',
        'context_id',
        'trait_key',
        'section',
        'parent_bullet_id',
        'content',
        'bullet_type',
        'is_sexual',
        'sort_order',
    ];

    protected $casts = [
        'is_sexual' => 'boolean',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_bullet_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_bullet_id');
    }

    public function dependentBackgrounds(): HasMany
    {
        return $this->hasMany(CharacterBackground::class, 'source_bullet_id');
    }
}
