<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class Taggable extends MorphPivot
{
    use \App\Traits\HasUuidV7;

    protected $table = 'taggables';

    public $incrementing = false;

    protected $keyType = 'string';
}
