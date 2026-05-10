<?php

namespace App\Domains\Matchmaking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchetypePrompt extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = ['archetype_id', 'agent_type', 'system_prompt'];

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }
}
