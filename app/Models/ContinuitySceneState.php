<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContinuitySceneState extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $table = 'continuity_scene_states';

    public $incrementing = true;

    protected $fillable = [
        'continuity_id',
        'activity_id',
        'objective',
        'constraints',
        'draft',
    ];
}
