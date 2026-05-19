<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DiscordBot extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'slug',
        'app_id',
        'tier',
        'is_active',
    ];

    protected $casts = [
        'tier'      => 'integer',
        'is_active' => 'boolean',
    ];

    public function guilds(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Domains\Community\Models\Guild::class,
            'guild_bots',
            'discord_bot_id',
            'guild_id'
        )->withPivot('installed_at')->withTimestamps();
    }
}
