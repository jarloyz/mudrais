<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContinuityQuestStatus extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'continuity_id',
        'activity_id',
        'quest_id',
        'status',
        'current_stage_number',
        'ai_summary',
    ];

    public function continuity(): BelongsTo
    {
        return $this->belongsTo(Continuity::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }
}
