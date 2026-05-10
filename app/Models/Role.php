<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use App\Traits\HasUuidV7;

class Role extends SpatieRole
{
    use HasUuidV7;

    public $incrementing = false;
    protected $keyType = 'string';
}
