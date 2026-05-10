<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Optimizable extends Model
{
    use \App\Traits\HasUuidV7;

    protected $table = 'optimizables';

    protected $fillable = [
        'optimizable_type',
        'optimizable_id',
        'optimized_text',
    ];

    public function optimizable(): MorphTo
    {
        return $this->morphTo();
    }
}
