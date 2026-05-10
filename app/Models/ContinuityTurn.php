<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContinuityTurn extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $table = 'continuity_turns';

    protected $fillable = [
        'continuity_id',
        'activity_id',
        'turn_index',
        'mode',
        'user_message',
        'output_md',
        'notes_json',
    ];
}
