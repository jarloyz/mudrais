<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationRecord extends Model
{
    protected $table = 'locations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(VaultRecord::class, 'vault_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(ContextRecord::class, 'context_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(SceneRecord::class, 'location_id');
    }
}
