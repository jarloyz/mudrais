<?php

namespace App\Domains\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildMember extends Model
{
    use \App\Traits\HasUuidV7;

    protected $table = 'guild_members';

    protected $fillable = ['player_id', 'guild_id', 'role'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
