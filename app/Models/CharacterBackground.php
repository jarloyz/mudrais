<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterBackground extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'character_id',
        'context_id',
        'category',
        'title',
        'summary',
        'detail',
        'is_sexual',
        'importance',
        'source_trait_key',
        'source_bullet_id',
        'sort_order',
    ];

    protected $casts = [
        'is_sexual' => 'boolean',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }

    public function sourceBullet(): BelongsTo
    {
        return $this->belongsTo(CharacterBullet::class, 'source_bullet_id');
    }
}
