<?php

namespace App\Domains\Community\Models;

use Illuminate\Database\Eloquent\Model;

class GuildItemOverride extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'guild_id',
        'item_key',
        'coin_delta',
        'energy_delta',
        'is_active',
    ];

    protected $casts = [
        'coin_delta'   => 'integer',
        'energy_delta' => 'integer',
        'is_active'    => 'boolean',
    ];
}
