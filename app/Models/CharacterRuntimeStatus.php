<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterRuntimeStatus extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $table = 'character_runtime_status';

    protected $fillable = [
        'continuity_id',
        'activity_id',
        'player_id',
        'character_id',
        'status_key',
        'status_value',
        'status_text',
        'unit',
        'source',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }
}
