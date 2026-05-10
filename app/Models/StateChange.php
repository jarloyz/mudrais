<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StateChange extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'change',
        'severity',
    ];
}
