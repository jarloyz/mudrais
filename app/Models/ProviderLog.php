<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderLog extends Model
{
    protected $fillable = [
        'agent',
        'model',
        'latency_ms',
        'total_tokens',
        'status',
    ];
}
