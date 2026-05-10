<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quest extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'vault_id',
        'title',
        'description',
        'type',
        'status',
    ];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(QuestStep::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
