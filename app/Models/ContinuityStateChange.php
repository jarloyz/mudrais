<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContinuityStateChange extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $table = 'continuity_state_changes';

    protected $fillable = [
        'continuity_id',
        'activity_id',
        'kind',
        'scope_type',
        'scope_id',
        'change',
        'severity',
    ];
}
