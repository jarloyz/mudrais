<?php

namespace App\Domains\Matchmaking\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AvatarProfile extends Pivot
{
    use HasUuidV7;

    protected $table = 'avatar_profile';

    public $incrementing = false;
    protected $keyType   = 'string';
}
