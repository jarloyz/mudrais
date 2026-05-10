<?php

namespace App\Domains\Matchmaking\Models;

use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use Illuminate\Database\Eloquent\Model;

class ArchetypeDraft extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'input_name',
        'input_text',
        'name_es',
        'name_en',
        'slug',
        'optimized_text_en',
        'semantic_tag_query',
        'style_vector',
        'suggested_tags',
        'status',
        'archetype_id',
        'processing_error',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'status' => ArchetypeDraftStatus::class,
        'style_vector' => 'array',
        'suggested_tags' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function getTagsCountAttribute(): int
    {
        return is_array($this->suggested_tags) ? count($this->suggested_tags) : 0;
    }

    public function archetype()
    {
        return $this->belongsTo(Archetype::class);
    }
}
