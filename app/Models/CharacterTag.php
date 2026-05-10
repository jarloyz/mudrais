<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class CharacterTag extends Pivot
{
    use \App\Traits\HasUuidV7;

    protected $table = 'character_tags';

    protected $fillable = [
        'character_id',
        'tag_id',
        'context_id',
    ];
}
