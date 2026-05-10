<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public $timestamps = false;

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Avatar::class, 'character_tags')
            ->withPivot('context_id')
            ->withTimestamps();
    }
}
