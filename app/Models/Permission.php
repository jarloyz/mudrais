<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use App\Traits\HasUuidV7;

class Permission extends SpatiePermission
{
    use HasUuidV7;

    public $incrementing = false;
    protected $keyType = 'string';
}
