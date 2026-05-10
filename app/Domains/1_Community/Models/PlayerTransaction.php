<?php

namespace App\Domains\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerTransaction extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'player_id',
        'type',
        'amount',
        'description',
        'balance_after',
        'metadata',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'amount'       => 'integer',
        'balance_after'=> 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
