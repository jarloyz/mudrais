<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QdrantLog extends Model
{
    protected $fillable = [
        'collection_name',
        'operation',
        'latency_ms',
        'matches_count',
        'status',
        'query_text',
        'top_result',
        'top_score',
    ];
}
