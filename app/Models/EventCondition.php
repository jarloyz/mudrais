<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCondition extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'event_id',
        'continuity_id',
        'scope_type',
        'operator',
        'value_text',
        'weight',
        'required',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'required' => 'boolean',
        'active' => 'boolean',
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
