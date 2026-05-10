<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    use \App\Traits\HasUuidV7;

    protected $table = 'ai_providers';

    protected $fillable = [
        'name',
        'slug',
        'driver',
        'base_url',
        'default_model',
        'api_key',
        'description',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
    ];

    public static function findBySlug(string $slug): ?static
    {
        return static::where('slug', $slug)->first();
    }

    public static function slugOptions(): array
    {
        return static::orderBy('name')->pluck('name', 'slug')->toArray();
    }
}
