<?php

namespace App\Domains\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GameItem extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = [
        'key',
        'name',
        'description',
        'type',
        'coin_delta',
        'energy_delta',
        'is_active',
    ];

    protected $casts = [
        'coin_delta'   => 'integer',
        'energy_delta' => 'integer',
        'is_active'    => 'boolean',
    ];

    /**
     * Devuelve los deltas efectivos para un ítem en un servidor concreto.
     * Los overrides por guild prevalecen sobre el valor base.
     *
     * @return array{coin_delta: int, energy_delta: int, name: string, description: string|null}
     *
     * @throws ModelNotFoundException  si el ítem no existe
     * @throws \RuntimeException       si el ítem está desactivado en este servidor
     */
    public static function resolveForGuild(string $key, string $guildId): array
    {
        $item = static::where('key', $key)->where('is_active', true)->firstOrFail();

        $override = GuildItemOverride::where('guild_id', $guildId)
            ->where('item_key', $key)
            ->first();

        if ($override && $override->is_active === false) {
            throw new \RuntimeException("El ítem '{$key}' está desactivado en este servidor.");
        }

        return [
            'coin_delta'   => $override?->coin_delta   ?? $item->coin_delta,
            'energy_delta' => $override?->energy_delta ?? $item->energy_delta,
            'name'         => $item->name,
            'description'  => $item->description,
        ];
    }
}
