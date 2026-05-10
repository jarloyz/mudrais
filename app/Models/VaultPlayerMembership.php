<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class VaultPlayerMembership extends Pivot
{
    use \App\Traits\HasUuidV7;

    protected $table = 'vault_player_memberships';

    protected $fillable = [
        'vault_id',
        'player_id',
        'role',
    ];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
