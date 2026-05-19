<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class InterviewMessage extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'thread_id',
        'discord_id',
        'guild_id',
        'content',
        'is_processed',
    ];

    protected $casts = [
        'is_processed' => 'boolean',
    ];

    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    public function scopeForThread($query, string $threadId)
    {
        return $query->where('thread_id', $threadId);
    }
}
