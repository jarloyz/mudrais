<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContinuityCommit extends Model
{
    use HasFactory, \App\Traits\HasUuidV7;

    protected $fillable = [
        'continuity_id',
        'activity_id',
        'parent_commit_id',
        'source_parent_commit_id',
        'turn_index',
        'mode',
        'message',
    ];

    public function continuity(): BelongsTo
    {
        return $this->belongsTo(Continuity::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_commit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_commit_id');
    }
}
