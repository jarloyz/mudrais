<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterInventory extends Model
{
    use \App\Traits\HasUuidV7;

    use HasFactory;

    protected $table = 'character_inventory';

    protected $fillable = [
        'character_id',
        'item_name',
        'category',
        'quantity',
        'is_quick_slot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_quick_slot' => 'boolean',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Avatar::class, 'character_id', 'id');
    }
}
