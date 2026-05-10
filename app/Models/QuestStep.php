<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestStep extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'quest_id',
        'stage_number',
        'description',
        'is_optional',
    ];

    protected $casts = [
        'is_optional' => 'boolean',
    ];

    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }
}
