<?php

namespace App\Domains\Narrative\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Continuity extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'parent_id',
        'root_id',
        'label',
        'status',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function commits(): HasMany
    {
        return $this->hasMany(\App\Models\ContinuityCommit::class);
    }
}
