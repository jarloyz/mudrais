<?php

namespace App\Domains\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildProfile extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = ['guild_id', 'discord_user_id', 'status'];

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
