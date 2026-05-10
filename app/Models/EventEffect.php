<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventEffect extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'event_id',
        'continuity_id',
        'effect_type',
        'kind',
        'scope_type',
        'scope_id',
        'change_text',
        'severity',
        'payload_json',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'payload_json' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function continuity(): BelongsTo
    {
        return $this->belongsTo(Continuity::class);
    }
}
