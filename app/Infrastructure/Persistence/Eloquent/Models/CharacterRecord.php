<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CharacterRecord extends Model
{
    protected $table = 'avatars';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(VaultRecord::class, 'vault_id');
    }

    public function scenes(): BelongsToMany
    {
        return $this->belongsToMany(SceneRecord::class, 'activity_members', 'avatar_id', 'activity_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }
}
