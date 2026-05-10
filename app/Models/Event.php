<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Event extends Model
{
    use HasFactory, \App\Traits\HasUuidV7;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'vault_id',
        'location_id',
        'quest_id',
        'title',
        'scene_id',
        'context_id',
        'date_label',
        'subject_character_id',
        'summary',
        'source',
        'brief',
        'detail',
        'importance',
        'priority',
        'status',
        'type',
        'cooldown_turns',
        'last_fired_turn',
    ];

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function subjectCharacter(): BelongsTo
    {
        return $this->belongsTo(Avatar::class, 'subject_character_id');
    }

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Avatar::class, 'event_characters')
            ->withPivot('role');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'event_locations')
            ->withPivot('role');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'event_tags', 'event_id', 'tag', 'id', 'name')
            ->withPivot('weight');
    }

    public function conditions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EventCondition::class);
    }

    public function effects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EventEffect::class);
    }

    public function runs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EventRun::class);
    }
}
