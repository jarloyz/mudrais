<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instantánea (snapshot) de un personaje al entrar a una escena.
 *
 * Protege las partidas en curso de ediciones posteriores en el Baúl.
 *
 * @property int    $id
 * @property string $activity_id
 * @property string $avatar_id
 * @property array  $snapshot_data
 * @property int    $version
 * @property string $snapshotted_at
 */
class CharacterInstance extends Model
{
    use \App\Traits\HasUuidV7;

    protected $table = 'character_instances';

    protected $fillable = [
        'activity_id',
        'avatar_id',
        'snapshot_data',
        'version',
        'snapshotted_at',
    ];

    protected $casts = [
        'snapshot_data' => 'array',
        'version' => 'integer',
        'snapshotted_at' => 'datetime',
    ];

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id', 'id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Avatar::class, 'avatar_id', 'id');
    }

    /**
     * Acceso tipado al snapshot de inventario.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inventory(): array
    {
        return $this->snapshot_data['inventory'] ?? [];
    }

    /**
     * Acceso tipado a los stats del snapshot.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->snapshot_data['stats'] ?? [];
    }
}
