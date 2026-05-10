<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRun extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    public $timestamps = false; // Solo usa created_at

    protected $fillable = [
        'event_id',
        'continuity_id',
        'scene_id',
        'turn_index',
        'score',
        'fired',
        'reasons_json',
        'effects_applied',
        'created_at',
    ];

    protected $casts = [
        'fired' => 'boolean',
        'reasons_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function continuity(): BelongsTo
    {
        return $this->belongsTo(Continuity::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
