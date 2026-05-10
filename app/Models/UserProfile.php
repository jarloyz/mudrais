<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'user_id',
        'display_name',
        'avatar_url',
        'bio',
        'timezone',
        'locale',
        'last_login_at',
        'last_login_ip',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
