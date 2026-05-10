<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SceneRecord extends Model
{
    protected $table = 'activities';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(VaultRecord::class, 'vault_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(LocationRecord::class, 'location_id');
    }

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(CharacterRecord::class, 'activity_members', 'activity_id', 'avatar_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }
}
