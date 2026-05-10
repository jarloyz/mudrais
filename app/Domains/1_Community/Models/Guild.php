<?php

namespace App\Domains\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Domains\Narrative\Models\Vault;

class Guild extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'discord_guild_id',
        'owner_discord_id',
        'stripe_id',
        'is_active',
        'plan_tier',
        'profile_quota',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    public function archetypes(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Archetype::class, 'archetype_guild')
            ->withPivot('is_primary')
            ->withTimestamps()
            ->orderByPivot('is_primary', 'desc');
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(GuildProfile::class);
    }

    public function vaults(): HasMany
    {
        return $this->hasMany(Vault::class);
    }

    public function activeProfileCount(): int
    {
        return $this->profiles()->where('status', 'active')->count();
    }

    public function hasQuotaAvailable(): bool
    {
        return $this->activeProfileCount() < $this->profile_quota;
    }
}
