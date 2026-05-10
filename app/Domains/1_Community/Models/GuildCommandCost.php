<?php

namespace App\Domains\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildCommandCost extends Model
{
    use \App\Traits\HasUuidV7;

    protected $table = 'guild_command_costs';

    protected $fillable = ['guild_id', 'command_name', 'energy_cost'];

    protected $casts = ['energy_cost' => 'integer'];

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }
}
