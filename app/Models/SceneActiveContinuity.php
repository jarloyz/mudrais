<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneActiveContinuity extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $table = 'scene_active_continuities';

    protected $primaryKey = 'activity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'activity_id',
        'continuity_id',
        'continuity_commit_id',
    ];

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }

    public function continuity(): BelongsTo
    {
        return $this->belongsTo(Continuity::class, 'continuity_id');
    }

    public function commit(): BelongsTo
    {
        return $this->belongsTo(ContinuityCommit::class, 'continuity_commit_id');
    }
}
