<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VaultRecord extends Model
{
    protected $table = 'vaults';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function characters(): HasMany
    {
        return $this->hasMany(CharacterRecord::class, 'vault_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(LocationRecord::class, 'vault_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(SceneRecord::class, 'vault_id');
    }
}
